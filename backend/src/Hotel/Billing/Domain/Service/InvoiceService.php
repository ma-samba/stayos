<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Application\DTO\RecordPaymentDTO;
use App\Hotel\Billing\Application\DTO\RefundDTO;
use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Hotel\NightAudit\Domain\Service\DailyCloseLockChecker;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Shared\Exception\BusinessRuleException;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Twig\Environment as Twig;

class InvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly AuditService            $auditService,
        private readonly MercurePublisher        $mercurePublisher,
        private readonly Twig                    $twig,
        private readonly DailyCloseLockChecker   $closeLockChecker,
        private readonly BusinessDateService     $businessDateService,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Passe une facture draft en issued (émise).
     */
    public function issue(Invoice $invoice, StaffUser $staff): void
    {
        if ($invoice->getStatusEnum() !== InvoiceStatus::DRAFT) {
            throw new BusinessRuleException('Seule une facture draft peut être émise.');
        }

        // Verrou night audit : pas d'émission rétroactive sur une réservation
        // dont une nuit appartient à une journée close — un avoir corrige
        // ce cas (hors V1).
        $this->closeLockChecker->assertCanModifyDate(
            $invoice->getReservation()->getCheckIn()
        );

        $invoice->setStatusEnum(InvoiceStatus::ISSUED);
        $invoice->setIssuedAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));

        $this->auditService->log(
            'invoice.issued',
            'Invoice',
            (string) $invoice->getId(),
            ['status' => InvoiceStatus::DRAFT->value],
            ['status' => InvoiceStatus::ISSUED->value],
            $staff,
        );

        $this->entityManager->flush();

        $this->logger->info('Invoice issued', [
            'invoice_id' => (string) $invoice->getId(),
            'number'     => $invoice->getNumber(),
            'totalXof'   => $invoice->getTotalXof(),
        ]);
    }

    /**
     * Enregistre un paiement manuel sur une facture.
     * Recalcule le statut (PAID si solde = 0, PARTIAL sinon).
     */
    public function recordPayment(Invoice $invoice, RecordPaymentDTO $dto, StaffUser $staff): Payment
    {
        if ($invoice->getStatusEnum() === InvoiceStatus::CANCELLED) {
            throw new BusinessRuleException(
                'Impossible d\'enregistrer un paiement sur une facture annulée.'
            );
        }

        // Verrou night audit : un paiement est toujours daté du jour
        // courant — on vérifie la business date courante. Un paiement
        // d'aujourd'hui sur une facture passée close reste accepté
        // (c'est précisément la mécanique du "geste corrigeant du jour").
        $this->closeLockChecker->assertCanModifyDate(
            $this->businessDateService->getCurrentBusinessDate()
        );

        $method = PaymentMethod::from($dto->method);

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setMethodEnum($method);
        $payment->setAmountXof($dto->amountXof);
        $payment->setStatusEnum(PaymentStatus::PAID);
        $payment->setReference($dto->reference);
        $payment->setNotes($dto->notes);
        $payment->setPaidAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));

        $invoice->addPayment($payment);
        $this->entityManager->persist($payment);

        // Recalcule le statut de la facture
        $previousStatus = $invoice->getStatus();
        if ($invoice->isFullyPaid()) {
            $invoice->setStatusEnum(InvoiceStatus::PAID);
        } else {
            $invoice->setStatusEnum(InvoiceStatus::PARTIAL);
        }

        $this->auditService->log(
            'payment.recorded',
            'Payment',
            'new',
            null,
            [
                'method'    => $method->value,
                'amountXof' => $dto->amountXof,
                'invoice'   => $invoice->getNumber(),
            ],
            $staff,
        );

        $this->entityManager->flush();

        $this->logger->info('Payment recorded', [
            'invoice_id'     => (string) $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
            'method'         => $method->value,
            'amountXof'      => $dto->amountXof,
            'new_status'     => $invoice->getStatus(),
            'previous_status'=> $previousStatus,
        ]);

        $this->mercurePublisher->publish('payment.received', [
            'invoiceId'     => (string) $invoice->getId(),
            'invoiceNumber' => $invoice->getNumber(),
            'amountXof'     => $dto->amountXof,
            'method'        => $method->value,
            'guestName'     => $invoice->getReservation()?->getGuest()?->getFullName(),
            'paidAt'        => $payment->getPaidAt()?->format('c'),
        ]);

        return $payment;
    }

    /**
     * Enregistre un remboursement (sortie de caisse) sur une facture.
     *
     * Matérialisation V1 minimaliste : un nouveau Payment avec montant
     * NÉGATIF et statut PAID. Pas de credit note dédiée, pas
     * d'intégration Paydunya. Le remboursement effectif est manuel
     * côté agent client.
     *
     * Le calcul de balance via `Invoice::getPaidXof()` somme
     * naturellement les paiements PAID (positifs + négatifs), donc
     * le solde reflète correctement la sortie.
     *
     * @param string $dto->amountXof Montant POSITIF saisi par l'utilisateur.
     */
    public function refundPayment(
        Invoice    $invoice,
        RefundDTO  $dto,
        StaffUser  $staff,
    ): Payment {
        // Verrou night audit : le refund est une opération du JOUR
        // courant (sortie de caisse aujourd'hui), pas de la date de
        // la facture. Cohérent avec recordPayment().
        $this->closeLockChecker->assertCanModifyDate(
            $this->businessDateService->getCurrentBusinessDate()
        );

        $reason = trim($dto->reason);

        // Garde anti-over-refund : on ne rembourse pas plus que ce qui
        // a été effectivement encaissé en net (paiements - refunds
        // antérieurs).
        $alreadyPaid = $invoice->getPaidXof();
        if (bccomp($dto->amountXof, $alreadyPaid, 2) > 0) {
            throw new BusinessRuleException(sprintf(
                'Impossible de rembourser %s XOF — seulement %s XOF effectivement payés sur cette facture.',
                $dto->amountXof,
                $alreadyPaid,
            ));
        }

        $method = PaymentMethod::from($dto->method);

        // Stockage en NÉGATIF (sortie de caisse)
        $negativeAmount = bcsub('0', bcadd($dto->amountXof, '0', 2), 2);

        $refund = new Payment();
        $refund->setInvoice($invoice);
        $refund->setMethodEnum($method);
        $refund->setAmountXof($negativeAmount);
        $refund->setStatusEnum(PaymentStatus::PAID);
        $refund->setPaidAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));
        $refund->setNotes(sprintf('[Remboursement] %s', $reason));

        $invoice->addPayment($refund);
        $this->entityManager->persist($refund);

        // Recalcul de statut Invoice (CANCELLED reste CANCELLED)
        $previousStatus = $invoice->getStatus();
        $newStatus      = $this->resolveStatusAfterRefund($invoice);
        if ($newStatus !== null) {
            $invoice->setStatusEnum($newStatus);
        }

        $this->auditService->log(
            action:     'payment.refunded',
            entityType: 'Payment',
            entityId:   (string) $refund->getId(),
            after:      [
                'invoice'        => $invoice->getNumber(),
                'method'         => $method->value,
                'amountRefunded' => bcadd($dto->amountXof, '0', 2),  // valeur saisie (positive)
                'storedAsXof'    => $negativeAmount,                  // valeur persistée (négative)
                'reason'         => $reason,
                'previousStatus' => $previousStatus,
                'newStatus'      => $invoice->getStatus(),
            ],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->logger->info('Payment refunded', [
            'invoice_id'     => (string) $invoice->getId(),
            'invoice_number' => $invoice->getNumber(),
            'method'         => $method->value,
            'amountXof'      => $dto->amountXof,
            'reason'         => $reason,
            'new_status'     => $invoice->getStatus(),
        ]);

        $this->mercurePublisher->publish('payment.refunded', [
            'invoiceId'     => (string) $invoice->getId(),
            'invoiceNumber' => $invoice->getNumber(),
            'amountXof'     => $dto->amountXof,
            'method'        => $method->value,
            'refundedAt'    => $refund->getPaidAt()?->format('c'),
        ]);

        return $refund;
    }

    /**
     * Détermine le nouveau statut Invoice après un refund.
     * CANCELLED reste CANCELLED. Sinon :
     *   - balance >= total → ISSUED (rien payé en net)
     *   - balance <= 0     → PAID (totalement payé ou surpayé)
     *   - sinon            → PARTIAL
     *
     * Retourne null si pas de transition (CANCELLED).
     */
    private function resolveStatusAfterRefund(Invoice $invoice): ?InvoiceStatus
    {
        if ($invoice->getStatusEnum() === InvoiceStatus::CANCELLED) {
            return null;
        }

        $balance = $invoice->getBalanceXof();
        $total   = $invoice->getTotalXof();

        if (bccomp($balance, $total, 2) >= 0) {
            return InvoiceStatus::ISSUED;
        }
        if (bccomp($balance, '0', 2) <= 0) {
            return InvoiceStatus::PAID;
        }

        return InvoiceStatus::PARTIAL;
    }

    /**
     * Genere le PDF d'une facture via Dompdf / Twig.
     *
     * @return string Le contenu binaire du PDF
     */
    public function generatePdf(Invoice $invoice): string
    {
        $hotel = $this->entityManager->getRepository(HotelProfile::class)->findOneBy([]);

        $html = $this->twig->render('billing/invoice_pdf.html.twig', [
            'invoice' => $invoice,
            'hotel'   => $hotel,
        ]);

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
