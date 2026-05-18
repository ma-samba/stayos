<?php

namespace App\DataFixtures;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\InvoiceLine;
use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class BillingFixtures extends Fixture implements DependentFixtureInterface
{
    public const INVOICE_PAID    = 'invoice-paid-1';
    public const INVOICE_PARTIAL = 'invoice-partial-1';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDependencies(): array
    {
        return [ReservationFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Tenant $savana */
        $savana = $this->getReference(TenantFixtures::SAVANA, Tenant::class);
        $schemaName = $savana->getSchemaName();

        $tz = new \DateTimeZone('Africa/Dakar');

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            // ── Facture PAYÉE (réservation passée) ──
            /** @var Reservation $resPast */
            $resPast = $this->getReference(ReservationFixtures::RES_PAST_1, Reservation::class);

            $invoicePaid = new Invoice();
            $invoicePaid->setReservation($resPast);
            $invoicePaid->setNumber('FAC-2026-00100');
            $invoicePaid->setStatusEnum(InvoiceStatus::PAID);
            $invoicePaid->setSubtotalXof('165000.00');
            $invoicePaid->setTaxRate('18.00');
            $invoicePaid->setTaxXof('29700.00');
            $invoicePaid->setTotalXof('194700.00');
            $invoicePaid->setIssuedAt(new \DateTimeImmutable('-7 days', $tz));
            $manager->persist($invoicePaid);

            // Lignes
            $line1 = new InvoiceLine();
            $line1->setInvoice($invoicePaid);
            $line1->setLabel('Nuitées — Chambre Deluxe 201 (3 nuits)');
            $line1->setQuantity(3);
            $line1->setUnitPriceXof('55000.00');
            $line1->setTotalXof('165000.00');
            $line1->setSortOrder(1);
            $manager->persist($line1);

            // Paiement complet Wave
            $payment1 = new Payment();
            $payment1->setInvoice($invoicePaid);
            $payment1->setMethodEnum(PaymentMethod::WAVE);
            $payment1->setAmountXof('194700.00');
            $payment1->setReference('WAVE-2026-00789');
            $payment1->setProcessedAt(new \DateTimeImmutable('-7 days 11:30', $tz));
            $manager->persist($payment1);

            $this->addReference(self::INVOICE_PAID, $invoicePaid);

            // ── Facture PARTIELLE (réservation en cours — check-in aujourd'hui) ──
            /** @var Reservation $resActive */
            $resActive = $this->getReference(ReservationFixtures::RES_ACTIVE_1, Reservation::class);

            $invoicePartial = new Invoice();
            $invoicePartial->setReservation($resActive);
            $invoicePartial->setNumber('FAC-2026-00142');
            $invoicePartial->setStatusEnum(InvoiceStatus::PARTIAL);
            $invoicePartial->setSubtotalXof('105000.00');
            $invoicePartial->setTaxRate('18.00');
            $invoicePartial->setTaxXof('18900.00');
            $invoicePartial->setTotalXof('123900.00');
            $invoicePartial->setIssuedAt(new \DateTimeImmutable('today', $tz));
            $manager->persist($invoicePartial);

            $line2 = new InvoiceLine();
            $line2->setInvoice($invoicePartial);
            $line2->setLabel('Nuitées — Chambre Standard 101 (3 nuits)');
            $line2->setQuantity(3);
            $line2->setUnitPriceXof('35000.00');
            $line2->setTotalXof('105000.00');
            $line2->setSortOrder(1);
            $manager->persist($line2);

            // Acompte Orange Money
            $payment2 = new Payment();
            $payment2->setInvoice($invoicePartial);
            $payment2->setMethodEnum(PaymentMethod::ORANGE_MONEY);
            $payment2->setAmountXof('50000.00');
            $payment2->setReference('OM-2026-00456');
            $payment2->setProcessedAt(new \DateTimeImmutable('today 14:15', $tz));
            $payment2->setNotes('Acompte à l\'arrivée');
            $manager->persist($payment2);

            $this->addReference(self::INVOICE_PARTIAL, $invoicePartial);

            $manager->flush();

        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }
}
