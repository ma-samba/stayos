<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Hotel\NightAudit\Domain\Service\NightAuditChecklistService;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class NightAuditChecklistServiceTest extends TestCase
{
    private MockObject&ReservationRepository $reservationRepo;
    private MockObject&InvoiceRepository     $invoiceRepo;
    private MockObject&RoomRepository        $roomRepo;
    private MockObject&BusinessDateService   $businessDate;
    private NightAuditChecklistService       $service;

    protected function setUp(): void
    {
        $this->reservationRepo = $this->createMock(ReservationRepository::class);
        $this->invoiceRepo     = $this->createMock(InvoiceRepository::class);
        $this->roomRepo        = $this->createMock(RoomRepository::class);
        $this->businessDate    = $this->createMock(BusinessDateService::class);

        $this->businessDate->method('getCurrentBusinessDate')->willReturn(
            new \DateTimeImmutable('2026-06-09 00:00:00', new \DateTimeZone('Africa/Dakar'))
        );

        $this->service = new NightAuditChecklistService(
            $this->reservationRepo,
            $this->invoiceRepo,
            $this->roomRepo,
            $this->businessDate,
        );
    }

    public function testEmptyStateReturnsNoWarnings(): void
    {
        $this->reservationRepo->method('findConfirmedArrivingOn')->willReturn([]);
        $this->reservationRepo->method('findCheckedInDepartingOn')->willReturn([]);
        $this->invoiceRepo->method('findDraftForReservationsCheckedOutOn')->willReturn([]);
        $this->roomRepo->method('findOccupiedWithoutActiveReservation')->willReturn([]);

        self::assertSame([], $this->service->buildWarnings());
    }

    public function testPendingArrivalProducesArrivalsPendingWarning(): void
    {
        $this->reservationRepo->method('findConfirmedArrivingOn')->willReturn([
            $this->makeReservation('RES-1', 'Diallo', '312'),
        ]);
        $this->reservationRepo->method('findCheckedInDepartingOn')->willReturn([]);
        $this->invoiceRepo->method('findDraftForReservationsCheckedOutOn')->willReturn([]);
        $this->roomRepo->method('findOccupiedWithoutActiveReservation')->willReturn([]);

        $warnings = $this->service->buildWarnings();

        self::assertCount(1, $warnings);
        self::assertSame('arrivals.pending', $warnings[0]['code']);
        self::assertSame('warning', $warnings[0]['severity']);
        self::assertSame(1, $warnings[0]['count']);
        self::assertCount(1, $warnings[0]['details']);
        self::assertSame('RES-1', $warnings[0]['details'][0]['confirmationNumber']);
    }

    public function testAllFourWarningsAreReportedTogether(): void
    {
        $this->reservationRepo->method('findConfirmedArrivingOn')->willReturn([
            $this->makeReservation('RES-A', 'A Guest', '101'),
        ]);
        $this->reservationRepo->method('findCheckedInDepartingOn')->willReturn([
            $this->makeReservation('RES-B', 'B Guest', '102'),
            $this->makeReservation('RES-C', 'C Guest', '103'),
        ]);
        $this->invoiceRepo->method('findDraftForReservationsCheckedOutOn')->willReturn([
            $this->makeInvoice('FAC-1', '85000.00', 'RES-X'),
        ]);
        $this->roomRepo->method('findOccupiedWithoutActiveReservation')->willReturn([
            $this->makeRoom('999'),
        ]);

        $warnings = $this->service->buildWarnings();

        $codes = array_column($warnings, 'code');
        self::assertEqualsCanonicalizing(
            ['arrivals.pending', 'departures.pending', 'invoices.draft', 'rooms.orphan_occupied'],
            $codes
        );
    }

    public function testDetailsAreCappedAtTenElements(): void
    {
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = $this->makeReservation("RES-$i", "Guest $i", (string) (100 + $i));
        }
        $this->reservationRepo->method('findConfirmedArrivingOn')->willReturn($items);
        $this->reservationRepo->method('findCheckedInDepartingOn')->willReturn([]);
        $this->invoiceRepo->method('findDraftForReservationsCheckedOutOn')->willReturn([]);
        $this->roomRepo->method('findOccupiedWithoutActiveReservation')->willReturn([]);

        $warnings = $this->service->buildWarnings();

        self::assertSame(25, $warnings[0]['count']);
        self::assertCount(10, $warnings[0]['details']);
    }

    private function makeReservation(string $confirmation, string $guestName, string $roomNumber): Reservation
    {
        $guest = $this->createMock(Guest::class);
        $guest->method('getFullName')->willReturn($guestName);

        $room = $this->createMock(Room::class);
        $room->method('getNumber')->willReturn($roomNumber);

        $r = $this->createMock(Reservation::class);
        $r->method('getId')->willReturn(Uuid::v4());
        $r->method('getConfirmationNumber')->willReturn($confirmation);
        $r->method('getGuest')->willReturn($guest);
        $r->method('getRoom')->willReturn($room);
        return $r;
    }

    private function makeInvoice(string $number, string $total, string $resNumber): Invoice
    {
        $res = $this->createMock(Reservation::class);
        $res->method('getConfirmationNumber')->willReturn($resNumber);

        $inv = $this->createMock(Invoice::class);
        $inv->method('getId')->willReturn(Uuid::v4());
        $inv->method('getNumber')->willReturn($number);
        $inv->method('getTotalXof')->willReturn($total);
        $inv->method('getReservation')->willReturn($res);
        return $inv;
    }

    private function makeRoom(string $number): Room
    {
        $r = $this->createMock(Room::class);
        $r->method('getId')->willReturn(Uuid::v4());
        $r->method('getNumber')->willReturn($number);
        return $r;
    }
}
