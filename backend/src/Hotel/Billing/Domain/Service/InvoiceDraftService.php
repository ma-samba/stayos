<?php

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\InvoiceLine;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;

class InvoiceDraftService
{
    private const TAX_RATE = '18.00';

    public function __construct(
        private readonly InvoiceRepository      $invoiceRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * Crée une facture draft à partir d'une réservation (au check-out).
     * Idempotent : si une facture existe déjà pour cette réservation, la retourne.
     */
    public function createFromReservation(Reservation $reservation): Invoice
    {
        $existing = $this->invoiceRepository->findOneBy(['reservation' => $reservation]);
        if ($existing !== null) {
            return $existing;
        }

        $nights = (int) $reservation->getCheckIn()->diff($reservation->getCheckOut())->days;

        // Source de vérité : reservation.totalXof (inclut saison + promo)
        $totalTtc   = (float) $reservation->getTotalXof();
        $taxRate    = (float) self::TAX_RATE;
        $subtotalHt = $totalTtc / (1 + $taxRate / 100);
        $taxXof     = $totalTtc - $subtotalHt;

        // Prix moyen/nuit pour cohérence comptable de la ligne (quantity × unitPrice == total)
        $unitPrice = $nights > 0 ? $totalTtc / $nights : $totalTtc;

        $invoice = new Invoice();
        $invoice->setReservation($reservation);
        $invoice->setNumber($this->invoiceRepository->generateInvoiceNumber());
        $invoice->setStatusEnum(InvoiceStatus::DRAFT);
        $invoice->setSubtotalXof(number_format($subtotalHt, 2, '.', ''));
        $invoice->setTaxRate(self::TAX_RATE);
        $invoice->setTaxXof(number_format($taxXof, 2, '.', ''));
        $invoice->setTotalXof(number_format($totalTtc, 2, '.', ''));

        $this->entityManager->persist($invoice);

        $line = new InvoiceLine();
        $line->setInvoice($invoice);
        $line->setLabel(sprintf(
            'Séjour chambre %s — %d nuit%s',
            $reservation->getRoom()->getNumber(),
            $nights,
            $nights > 1 ? 's' : '',
        ));
        $line->setQuantity($nights);
        $line->setUnitPriceXof(number_format($unitPrice, 2, '.', ''));
        $line->setTotalXof(number_format($totalTtc, 2, '.', ''));
        $line->setSortOrder(0);

        $this->entityManager->persist($line);
        $this->entityManager->flush();

        return $invoice;
    }
}
