<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\InvoiceLine;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Service\InvoiceDraftService;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Room\Domain\Entity\Room;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class InvoiceDraftServiceTest extends TestCase
{
    private InvoiceDraftService $service;
    private MockObject&InvoiceRepository $invoiceRepo;
    private MockObject&EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->invoiceRepo   = $this->createMock(InvoiceRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new InvoiceDraftService(
            $this->invoiceRepo,
            $this->entityManager,
        );
    }

    private function makeReservation(string $rate = '55000.00', int $nights = 5, ?string $totalXof = null): Reservation
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $checkIn  = new \DateTimeImmutable('2026-06-01', $tz);
        $checkOut = $checkIn->modify("+{$nights} days");

        // totalXof defaults to rate × nights (base case, no season/promo)
        $total = $totalXof ?? number_format((float) $rate * $nights, 2, '.', '');

        $room = $this->createMock(Room::class);
        $room->method('getNumber')->willReturn('205');

        $reservation = $this->createMock(Reservation::class);
        $reservation->method('getId')->willReturn(Uuid::v4());
        $reservation->method('getCheckIn')->willReturn($checkIn);
        $reservation->method('getCheckOut')->willReturn($checkOut);
        $reservation->method('getRateXof')->willReturn($rate);
        $reservation->method('getTotalXof')->willReturn($total);
        $reservation->method('getRoom')->willReturn($room);

        return $reservation;
    }

    // ── Test 1 : TVA extraction from TTC ──

    public function testTvaExtractionFromTtc(): void
    {
        $reservation = $this->makeReservation('55000.00', 5);

        $this->invoiceRepo->method('findOneBy')->willReturn(null);
        $this->invoiceRepo->method('generateInvoiceNumber')->willReturn('FAC-2026-00001');

        /** @var Invoice|null $capturedInvoice */
        $capturedInvoice = null;

        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) use (&$capturedInvoice) {
                if ($entity instanceof Invoice) {
                    $capturedInvoice = $entity;
                }
            });

        $invoice = $this->service->createFromReservation($reservation);

        // 5 nuits × 55 000 = 275 000 TTC
        $this->assertEquals('275000.00', $invoice->getTotalXof());

        // subtotalHt = 275000 / 1.18 ≈ 233050.85
        $this->assertEqualsWithDelta(233050.85, (float) $invoice->getSubtotalXof(), 0.01);

        // taxXof = 275000 - 233050.85 ≈ 41949.15
        $this->assertEqualsWithDelta(41949.15, (float) $invoice->getTaxXof(), 0.01);

        $this->assertEquals('18.00', $invoice->getTaxRate());
        $this->assertEquals('draft', $invoice->getStatus());
    }

    // ── Test 2 : Invoice line matches nights ──

    public function testInvoiceLineMatchesNights(): void
    {
        $reservation = $this->makeReservation('45000.00', 3);

        $this->invoiceRepo->method('findOneBy')->willReturn(null);
        $this->invoiceRepo->method('generateInvoiceNumber')->willReturn('FAC-2026-00002');

        /** @var InvoiceLine|null $capturedLine */
        $capturedLine = null;

        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) use (&$capturedLine) {
                if ($entity instanceof InvoiceLine) {
                    $capturedLine = $entity;
                }
            });

        $invoice = $this->service->createFromReservation($reservation);

        $this->assertNotNull($capturedLine);
        $this->assertEquals(3, $capturedLine->getQuantity());
        $this->assertEquals('45000.00', $capturedLine->getUnitPriceXof());
        $this->assertEquals('135000.00', $capturedLine->getTotalXof());
        $this->assertStringContainsString('205', $capturedLine->getLabel());
        $this->assertStringContainsString('3 nuits', $capturedLine->getLabel());
    }

    // ── Test 3 : Single night — no plural ──

    public function testSingleNightLabel(): void
    {
        $reservation = $this->makeReservation('80000.00', 1);

        $this->invoiceRepo->method('findOneBy')->willReturn(null);
        $this->invoiceRepo->method('generateInvoiceNumber')->willReturn('FAC-2026-00003');

        /** @var InvoiceLine|null $capturedLine */
        $capturedLine = null;

        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) use (&$capturedLine) {
                if ($entity instanceof InvoiceLine) {
                    $capturedLine = $entity;
                }
            });

        $this->service->createFromReservation($reservation);

        $this->assertNotNull($capturedLine);
        $this->assertEquals(1, $capturedLine->getQuantity());
        $this->assertStringContainsString('1 nuit', $capturedLine->getLabel());
        $this->assertStringNotContainsString('nuits', $capturedLine->getLabel());
    }

    // ── Test 4 : Idempotency — returns existing invoice ──

    public function testIdempotencyReturnsExistingInvoice(): void
    {
        $reservation = $this->makeReservation();

        $existingInvoice = new Invoice();
        $existingInvoice->setNumber('FAC-2026-00099');
        $existingInvoice->setTotalXof('275000.00');
        $existingInvoice->setSubtotalXof('233050.85');
        $existingInvoice->setTaxXof('41949.15');

        $this->invoiceRepo->method('findOneBy')->willReturn($existingInvoice);

        // persist/flush should NOT be called
        $this->entityManager->expects($this->never())->method('persist');
        $this->entityManager->expects($this->never())->method('flush');

        $result = $this->service->createFromReservation($reservation);

        $this->assertSame($existingInvoice, $result);
        $this->assertEquals('FAC-2026-00099', $result->getNumber());
    }

    // ── Test 5 : Total TTC consistency ──

    public function testSubtotalPlusTaxEqualsTotalTtc(): void
    {
        $reservation = $this->makeReservation('35000.00', 7);

        $this->invoiceRepo->method('findOneBy')->willReturn(null);
        $this->invoiceRepo->method('generateInvoiceNumber')->willReturn('FAC-2026-00004');
        $this->entityManager->method('persist');

        $invoice = $this->service->createFromReservation($reservation);

        $subtotal = (float) $invoice->getSubtotalXof();
        $tax      = (float) $invoice->getTaxXof();
        $total    = (float) $invoice->getTotalXof();

        // subtotal + tax must equal total (within rounding)
        $this->assertEqualsWithDelta($total, $subtotal + $tax, 0.02);

        // Total must be rate × nights
        $this->assertEquals('245000.00', $invoice->getTotalXof());
    }
}
