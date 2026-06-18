<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\InvoiceLine;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Émet une facture dédiée aux frais non-rendus (no-show / annulation).
 *
 * Cohabite avec `InvoiceDraftService` qui gère les factures de séjour
 * standards. Le statut est `ISSUED` direct (pas de phase DRAFT) parce
 * que les frais sont calculés et appliqués au moment de l'action — le
 * client doit pouvoir régler immédiatement.
 *
 * TVA 18% appliquée par cohérence avec les factures de séjour
 * (TAX_RATE constante alignée sur InvoiceDraftService).
 */
class FeeInvoiceService
{
    private const TAX_RATE_PERCENT = '18.00';

    public const KIND_NO_SHOW      = 'no_show';
    public const KIND_CANCELLATION = 'cancellation';

    public function __construct(
        private readonly InvoiceRepository      $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditService           $auditService,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Crée et émet une facture pour des frais non-rendus.
     *
     * @param string $kind self::KIND_NO_SHOW ou self::KIND_CANCELLATION
     * @param string $amountXof TTC, doit être strictement positif
     */
    public function createFeeInvoice(
        Reservation $reservation,
        string      $kind,
        string      $amountXof,
        string      $description,
        ?StaffUser  $staff,
    ): Invoice {
        if (!in_array($kind, [self::KIND_NO_SHOW, self::KIND_CANCELLATION], true)) {
            throw new BusinessRuleException(sprintf(
                'Type de facture de frais inconnu : %s',
                $kind,
            ));
        }
        if (bccomp($amountXof, '0', 2) <= 0) {
            throw new BusinessRuleException(
                'Le montant des frais doit être strictement positif.'
            );
        }

        // TTC -> HT -> TVA en bcmath (2 décimales)
        $totalTtc = bcadd($amountXof, '0', 2);
        $subtotalHt = bcdiv($totalTtc, '1.18', 2);
        $taxXof    = bcsub($totalTtc, $subtotalHt, 2);

        $invoice = new Invoice();
        $invoice->setReservation($reservation);
        $invoice->setNumber($this->invoiceRepository->generateInvoiceNumber());
        $invoice->setStatusEnum(InvoiceStatus::ISSUED);
        $invoice->setIssuedAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));
        $invoice->setSubtotalXof($subtotalHt);
        $invoice->setTaxRate(self::TAX_RATE_PERCENT);
        $invoice->setTaxXof($taxXof);
        $invoice->setTotalXof($totalTtc);

        $this->entityManager->persist($invoice);

        $line = new InvoiceLine();
        $line->setInvoice($invoice);
        $line->setLabel($description);
        $line->setQuantity(1);
        $line->setUnitPriceXof($totalTtc);
        $line->setTotalXof($totalTtc);
        $line->setSortOrder(0);

        $this->entityManager->persist($line);

        $this->auditService->log(
            action:     sprintf('invoice.%s_fee_created', $kind),
            entityType: 'Invoice',
            entityId:   (string) $invoice->getId(),
            after:      [
                'reservation' => $reservation->getConfirmationNumber(),
                'kind'        => $kind,
                'totalXof'    => $totalTtc,
            ],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->logger->info('invoice.fee_created', [
            'kind'        => $kind,
            'reservation' => $reservation->getConfirmationNumber(),
            'totalXof'    => $totalTtc,
            'invoice_id'  => (string) $invoice->getId(),
        ]);

        return $invoice;
    }
}
