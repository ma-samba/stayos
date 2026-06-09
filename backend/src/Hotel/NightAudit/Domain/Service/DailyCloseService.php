<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Service;

use App\Hotel\Analytics\Domain\Service\KpiService;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Billing\Infrastructure\Repository\PaymentRepository;
use App\Hotel\NightAudit\Domain\Entity\DailyClose;
use App\Hotel\NightAudit\Infrastructure\Repository\DailyCloseRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

class DailyCloseService
{
    public function __construct(
        private readonly EntityManagerInterface         $entityManager,
        private readonly DailyCloseRepository           $repository,
        private readonly BusinessDateService            $businessDate,
        private readonly KpiService                     $kpiService,
        private readonly PaymentRepository              $paymentRepository,
        private readonly InvoiceRepository              $invoiceRepository,
        private readonly RoomRepository                 $roomRepository,
        private readonly TenantContext                  $tenantContext,
        private readonly AuditService                   $auditService,
        private readonly NightAuditChecklistService     $checklistService,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * État courant du night audit pour le controller.
     *
     * @return array{
     *     businessDate: string,
     *     lastCloseDate: string|null,
     *     canClose: bool,
     *     reason: string|null,
     *     alreadyClosed: bool
     * }
     */
    public function getCurrent(): array
    {
        $businessDate = $this->businessDate->getCurrentBusinessDate();
        $lastClose    = $this->repository->findLatestEffective();

        if ($lastClose === null) {
            return [
                'businessDate'  => $businessDate->format('Y-m-d'),
                'lastCloseDate' => null,
                'canClose'      => true,
                'reason'        => null,
                'alreadyClosed' => false,
            ];
        }

        $lastDate = $lastClose->getBusinessDate();

        // Déjà clôturée aujourd'hui (ou au-delà, défensif)
        if ($lastDate->format('Y-m-d') === $businessDate->format('Y-m-d')) {
            return [
                'businessDate'  => $businessDate->format('Y-m-d'),
                'lastCloseDate' => $lastDate->format('Y-m-d'),
                'canClose'      => false,
                'reason'        => 'La journée courante est déjà clôturée.',
                'alreadyClosed' => true,
            ];
        }

        // Séquentialité : il faut que lastDate = businessDate - 1
        $expected = $businessDate->modify('-1 day');
        if ($lastDate < $expected) {
            $missing = (int) $lastDate->diff($expected)->days;
            return [
                'businessDate'  => $businessDate->format('Y-m-d'),
                'lastCloseDate' => $lastDate->format('Y-m-d'),
                'canClose'      => false,
                'reason'        => sprintf(
                    "La journée du %s doit être clôturée d'abord (%d journée(s) manquante(s) avant aujourd'hui).",
                    $lastDate->modify('+1 day')->format('d/m/Y'),
                    $missing,
                ),
                'alreadyClosed' => false,
            ];
        }

        return [
            'businessDate'  => $businessDate->format('Y-m-d'),
            'lastCloseDate' => $lastDate->format('Y-m-d'),
            'canClose'      => true,
            'reason'        => null,
            'alreadyClosed' => false,
        ];
    }

    /**
     * Clôture la business date courante. RBAC géré côté controller
     * (RECEPTIONIST ou plus).
     *
     * Si la checklist remonte des warnings et que $force est false,
     * la clôture est refusée (BusinessRuleException). L'UI doit alors
     * présenter les warnings et re-soumettre avec force=true.
     */
    public function close(StaffUser $actor, bool $force = false): DailyClose
    {
        $state = $this->getCurrent();
        if (!$state['canClose']) {
            throw new BusinessRuleException(
                $state['reason'] ?? 'Clôture impossible.'
            );
        }

        $warnings = $this->checklistService->buildWarnings();
        if ($warnings !== [] && !$force) {
            throw new BusinessRuleException(sprintf(
                "Clôture refusée : %d avertissement(s) à confirmer. " .
                "Renvoyez la requête avec force=true pour passer outre.",
                count($warnings)
            ));
        }

        $tenant       = $this->tenantContext->get();
        $businessDate = $this->businessDate->getCurrentBusinessDate();
        $cutoffHour   = $tenant->getBusinessDayCutoffHour();
        $snapshot     = $this->buildSnapshot($businessDate);
        $snapshot['warnings'] = $warnings; // traçabilité dans le snapshot

        $close = new DailyClose();
        $close->setBusinessDate($businessDate);
        $close->setClosedAt(new \DateTimeImmutable('now'));
        $close->setClosedById($actor->getId());
        $close->setClosedByEmail($actor->getEmail());
        $close->setCutoffHour($cutoffHour);
        $close->setSnapshot($snapshot);

        $this->entityManager->persist($close);

        $this->auditService->log(
            action:     'night_audit.closed',
            entityType: 'daily_close',
            entityId:   'new',
            after:      [
                'businessDate'  => $businessDate->format('Y-m-d'),
                'cutoffHour'    => $cutoffHour,
                'revenueTtcXof' => $snapshot['kpis']['revenueTtcXof'] ?? null,
                'occupancyRate' => $snapshot['kpis']['occupancyRate'] ?? null,
                'warningsCount' => count($warnings),
                'forced'        => $force,
            ],
            staffUser:  $actor,
        );

        $this->entityManager->flush();

        $this->logger->info('night_audit.closed', [
            'tenant'         => $tenant->getSlug(),
            'business_date'  => $businessDate->format('Y-m-d'),
            'closed_by'      => $actor->getEmail(),
            'warnings_count' => count($warnings),
            'forced'         => $force,
        ]);

        return $close;
    }

    /**
     * Réouverture d'une clôture. Sécurité : seule la dernière clôture
     * effective peut être rouverte (sinon on créerait des trous).
     * RBAC MANAGER géré côté controller.
     */
    public function reopen(DailyClose $close, StaffUser $actor, string $reason): DailyClose
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new BusinessRuleException(
                'La raison de la réouverture doit faire au moins 5 caractères.'
            );
        }
        if ($close->isReopened()) {
            throw new BusinessRuleException(
                'Cette clôture est déjà rouverte.'
            );
        }

        $latest = $this->repository->findLatestEffective();
        if ($latest === null || !$latest->getId()->equals($close->getId())) {
            throw new BusinessRuleException(
                'Seule la dernière clôture effective peut être rouverte.'
            );
        }

        $close->setReopenedAt(new \DateTimeImmutable('now'));
        $close->setReopenedById($actor->getId());
        $close->setReopenedByEmail($actor->getEmail());
        $close->setReopenReason($reason);

        $this->auditService->log(
            action:     'night_audit.reopened',
            entityType: 'daily_close',
            entityId:   (string) $close->getId(),
            after:      [
                'businessDate' => $close->getBusinessDate()->format('Y-m-d'),
                'reason'       => $reason,
            ],
            staffUser:  $actor,
        );

        $this->entityManager->flush();

        $this->logger->info('night_audit.reopened', [
            'tenant'        => $this->tenantContext->get()->getSlug(),
            'business_date' => $close->getBusinessDate()->format('Y-m-d'),
            'reopened_by'   => $actor->getEmail(),
            'reason'        => $reason,
        ]);

        return $close;
    }

    /**
     * Construit le payload JSON figé.
     *
     * @return array<string, mixed>
     */
    private function buildSnapshot(\DateTimeImmutable $businessDate): array
    {
        $kpis             = $this->kpiService->dashboardForDate($businessDate);
        $cashByMethod     = $this->paymentRepository->sumPaidByMethodForDate($businessDate);
        $invoicesIssued   = $this->invoiceRepository->countAndSumIssuedForDate($businessDate);
        $roomsSnapshot    = $this->roomRepository->snapshotAllStatuses();

        $cashTotal = '0.00';
        foreach ($cashByMethod as $amount) {
            $cashTotal = bcadd($cashTotal, $amount, 2);
        }

        return [
            'kpis' => [
                'occupancyRate'    => $kpis->occupancyRate,
                'adrHtXof'         => $kpis->adrHt,
                'revparHtXof'      => $kpis->revparHt,
                'revenueHtXof'     => $kpis->roomRevenueHt,
                'revenueTtcXof'    => $kpis->roomRevenueTtc,
                'soldNights'       => $kpis->roomNightsSold,
                'availableNights'  => $kpis->roomNightsAvailable,
                'occupiedRooms'    => $kpis->occupiedRooms,
                'availableRooms'   => $kpis->availableRooms,
            ],
            'counts' => [
                'arrivals'   => $kpis->arrivalsToday,
                'departures' => $kpis->departuresToday,
            ],
            'cash' => [
                'byMethod' => $cashByMethod,
                'totalXof' => $cashTotal,
            ],
            'invoices' => [
                'issued'   => $invoicesIssued['count'],
                'totalXof' => $invoicesIssued['total'],
            ],
            'rooms' => $roomsSnapshot,
            'meta' => [
                'businessDate' => $businessDate->format('Y-m-d'),
                'generatedAt'  => (new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            ],
        ];
    }
}
