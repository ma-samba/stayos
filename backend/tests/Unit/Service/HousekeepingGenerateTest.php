<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Housekeeping\Domain\Service\HousekeepingService;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class HousekeepingGenerateTest extends TestCase
{
    private HousekeepingService $service;
    private MockObject&EntityManagerInterface $em;
    private MockObject&CleaningTaskRepository $taskRepo;
    private MockObject&ReservationRepository $reservationRepo;

    private \DateTimeZone $tz;

    protected function setUp(): void
    {
        $this->em              = $this->createMock(EntityManagerInterface::class);
        $this->taskRepo        = $this->createMock(CleaningTaskRepository::class);
        $this->reservationRepo = $this->createMock(ReservationRepository::class);

        $this->service = new HousekeepingService(
            $this->em,
            $this->createMock(AuditService::class),
            $this->createMock(MercurePublisher::class),
            $this->taskRepo,
            $this->reservationRepo,
            $this->createMock(LoggerInterface::class),
        );

        $this->tz = new \DateTimeZone('Africa/Dakar');
    }

    /**
     * Crée une réservation CHECKED_IN avec les dates données.
     */
    private function makeCheckedInReservation(string $checkIn, string $checkOut): Reservation
    {
        $room = $this->createMock(Room::class);
        $room->method('getId')->willReturn(Uuid::v4());
        $room->method('getNumber')->willReturn('101');

        $reservation = new Reservation();

        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, Uuid::v4());

        $reservation->setStatusEnum(ReservationStatus::CHECKED_IN);
        $reservation->setCheckIn(new \DateTimeImmutable($checkIn, $this->tz));
        $reservation->setCheckOut(new \DateTimeImmutable($checkOut, $this->tz));
        $reservation->setRoom($room);
        $reservation->setRateXof('45000.00');
        $reservation->setTotalXof('135000.00');
        $reservation->setConfirmationNumber('RES-2026-00001');
        $reservation->setAdults(1);
        $reservation->setChildren(0);

        return $reservation;
    }

    // ── Test 1 : Pas de recouche le jour d'arrivée ──

    public function testNoTaskOnArrivalDay(): void
    {
        // Séjour 10→13 juin, on génère le 10 (jour d'arrivée)
        $reservation = $this->makeCheckedInReservation('2026-06-10', '2026-06-13');
        $this->reservationRepo->method('findWithFilters')->willReturn([$reservation]);

        $date = new \DateTimeImmutable('2026-06-10', $this->tz);

        $this->em->expects($this->never())->method('persist');

        $count = $this->service->generateDailyTasks($date);

        $this->assertEquals(0, $count);
    }

    // ── Test 2 : Pas de recouche le jour de départ ──

    public function testNoTaskOnDepartureDay(): void
    {
        // Séjour 10→13 juin, on génère le 13 (jour de départ)
        $reservation = $this->makeCheckedInReservation('2026-06-10', '2026-06-13');
        $this->reservationRepo->method('findWithFilters')->willReturn([$reservation]);

        $date = new \DateTimeImmutable('2026-06-13', $this->tz);

        $this->em->expects($this->never())->method('persist');

        $count = $this->service->generateDailyTasks($date);

        $this->assertEquals(0, $count);
    }

    // ── Test 3 : Recouche créée sur un jour plein ──

    public function testTaskCreatedOnFullDay(): void
    {
        // Séjour 10→13 juin, on génère le 11 (jour plein)
        $reservation = $this->makeCheckedInReservation('2026-06-10', '2026-06-13');
        $this->reservationRepo->method('findWithFilters')->willReturn([$reservation]);
        $this->taskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(false);

        $date = new \DateTimeImmutable('2026-06-11', $this->tz);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $count = $this->service->generateDailyTasks($date);

        $this->assertEquals(1, $count);
    }

    // ── Test 4 : Séjour d'une nuit → aucune recouche ──

    public function testOneNightStayNoTask(): void
    {
        // Séjour 10→11 juin (1 nuit), aucun jour plein intermédiaire
        $reservation = $this->makeCheckedInReservation('2026-06-10', '2026-06-11');
        $this->reservationRepo->method('findWithFilters')->willReturn([$reservation]);

        // Jour 10 = arrivée → exclu
        $this->em->expects($this->never())->method('persist');

        $count10 = $this->service->generateDailyTasks(new \DateTimeImmutable('2026-06-10', $this->tz));
        $this->assertEquals(0, $count10);

        // Jour 11 = départ → exclu
        $count11 = $this->service->generateDailyTasks(new \DateTimeImmutable('2026-06-11', $this->tz));
        $this->assertEquals(0, $count11);
    }

    // ── Test 5 : Idempotent — pas de doublon si tâche active existe ──

    public function testIdempotentSkipsExistingTask(): void
    {
        // Séjour 10→13 juin, jour 12 = jour plein, mais tâche déjà existante
        $reservation = $this->makeCheckedInReservation('2026-06-10', '2026-06-13');
        $this->reservationRepo->method('findWithFilters')->willReturn([$reservation]);
        $this->taskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(true);

        $date = new \DateTimeImmutable('2026-06-12', $this->tz);

        $this->em->expects($this->never())->method('persist');

        $count = $this->service->generateDailyTasks($date);

        $this->assertEquals(0, $count);
    }
}
