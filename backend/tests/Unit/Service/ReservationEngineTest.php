<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Domain\Service\InvoiceDraftService;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\Service\PriceCalculator;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use App\Hotel\Rate\Infrastructure\Repository\RatePlanRepository;
use App\Hotel\Rate\Infrastructure\Repository\SeasonalRateRepository;
use App\Hotel\Reservation\Application\DTO\CreateReservationDTO;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Domain\Service\ConflictChecker;
use App\Hotel\Reservation\Domain\Service\ReservationEngine;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\ConflictException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class ReservationEngineTest extends TestCase
{
    private ReservationEngine $engine;
    private MockObject&ReservationRepository $reservationRepo;
    private MockObject&RoomRepository $roomRepo;
    private MockObject&GuestRepository $guestRepo;
    private MockObject&ConflictChecker $conflictChecker;
    private MockObject&AuditService $auditService;
    private MockObject&MercurePublisher $mercurePublisher;
    private MockObject&InvoiceDraftService $invoiceDraftService;
    private MockObject&CleaningTaskRepository $cleaningTaskRepo;
    private MockObject&LoggerInterface $logger;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&RatePlanRepository $ratePlanRepo;
    private MockObject&PromotionRepository $promotionRepo;
    private MockObject&StaffUser $staff;

    protected function setUp(): void
    {
        $this->reservationRepo     = $this->createMock(ReservationRepository::class);
        $this->roomRepo            = $this->createMock(RoomRepository::class);
        $this->guestRepo           = $this->createMock(GuestRepository::class);
        $this->conflictChecker     = $this->createMock(ConflictChecker::class);
        $this->auditService        = $this->createMock(AuditService::class);
        $this->mercurePublisher    = $this->createMock(MercurePublisher::class);
        $this->invoiceDraftService = $this->createMock(InvoiceDraftService::class);
        $this->cleaningTaskRepo    = $this->createMock(CleaningTaskRepository::class);
        $this->logger              = $this->createMock(LoggerInterface::class);
        $this->entityManager       = $this->createMock(EntityManagerInterface::class);
        $this->ratePlanRepo        = $this->createMock(RatePlanRepository::class);
        $this->promotionRepo       = $this->createMock(PromotionRepository::class);

        // Real PriceCalculator with mocked repos returning empty → base × nights
        $seasonalRepo = $this->createMock(SeasonalRateRepository::class);
        $seasonalRepo->method('findActiveForDate')->willReturn([]);
        $priceCalculator = new PriceCalculator($seasonalRepo, $this->promotionRepo);

        // Stub HotelProfile repository for resolveHotelId()
        $hotelProfile = $this->createMock(HotelProfile::class);
        $hotelProfile->method('getId')->willReturn(Uuid::v4());
        $hotelProfileRepo = $this->createMock(EntityRepository::class);
        $hotelProfileRepo->method('findOneBy')->willReturn($hotelProfile);
        $this->entityManager->method('getRepository')
            ->with(HotelProfile::class)
            ->willReturn($hotelProfileRepo);

        $this->engine = new ReservationEngine(
            $this->reservationRepo,
            $this->roomRepo,
            $this->guestRepo,
            $this->conflictChecker,
            $this->auditService,
            $this->mercurePublisher,
            $this->invoiceDraftService,
            $this->cleaningTaskRepo,
            $this->logger,
            $this->entityManager,
            $priceCalculator,
            $this->ratePlanRepo,
            $this->promotionRepo,
        );

        $this->staff = $this->createMock(StaffUser::class);
    }

    private function makeRoom(string $number = '101', string $baseRate = '45000.00'): Room
    {
        $roomType = $this->createMock(RoomType::class);
        $roomType->method('getBaseRateXof')->willReturn($baseRate);

        $room = $this->createMock(Room::class);
        $room->method('getId')->willReturn(Uuid::v4());
        $room->method('getNumber')->willReturn($number);
        $room->method('getType')->willReturn($roomType);

        return $room;
    }

    private function makeGuest(string $firstName = 'Amadou', string $lastName = 'Diallo'): Guest
    {
        $guest = $this->createMock(Guest::class);
        $guest->method('getId')->willReturn(Uuid::v4());
        $guest->method('getFullName')->willReturn($firstName . ' ' . $lastName);

        return $guest;
    }

    private function makeReservation(string $status = 'confirmed', ?Room $room = null, ?Guest $guest = null): Reservation
    {
        $reservation = new Reservation();

        // Set ID via reflection (normally set by Doctrine on persist)
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, Uuid::v4());

        $reservation->setStatusEnum(ReservationStatus::from($status));
        $reservation->setCheckIn(new \DateTimeImmutable('2026-06-01', new \DateTimeZone('Africa/Dakar')));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-06-04', new \DateTimeZone('Africa/Dakar')));
        $reservation->setRateXof('45000.00');
        $reservation->setTotalXof('135000.00');
        $reservation->setConfirmationNumber('RES-2026-00001');
        $reservation->setAdults(2);
        $reservation->setChildren(0);

        if ($room) {
            $reservation->setRoom($room);
        }
        if ($guest) {
            $reservation->setGuest($guest);
        }

        return $reservation;
    }

    // ── Test 1 : Création réussie ──

    public function testCreateReservationSuccess(): void
    {
        $room  = $this->makeRoom('312', '45000.00');
        $guest = $this->makeGuest();

        $this->roomRepo->method('find')->willReturn($room);
        $this->guestRepo->method('find')->willReturn($guest);
        $this->conflictChecker->expects($this->once())->method('assertAvailable');
        $this->reservationRepo->method('generateConfirmationNumber')->willReturn('RES-2026-00042');
        $this->entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(function (object $entity) {
                if ($entity instanceof Reservation) {
                    $ref = new \ReflectionProperty(Reservation::class, 'id');
                    $ref->setValue($entity, Uuid::v4());
                }
            });
        $this->entityManager->expects($this->once())->method('flush');

        $dto = new CreateReservationDTO();
        $dto->roomId   = (string) Uuid::v4();
        $dto->guestId  = (string) Uuid::v4();
        $dto->checkIn  = '2026-06-01';
        $dto->checkOut = '2026-06-04';
        $dto->adults   = 2;

        $reservation = $this->engine->create($dto, $this->staff);

        $this->assertEquals('RES-2026-00042', $reservation->getConfirmationNumber());
        $this->assertEquals('confirmed', $reservation->getStatus());
        $this->assertEquals('135000.00', $reservation->getTotalXof());
        $this->assertEquals(3, $reservation->getNights());
    }

    // ── Test 2 : Conflit de réservation ──

    public function testCreateReservationConflictThrows(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $this->roomRepo->method('find')->willReturn($room);
        $this->guestRepo->method('find')->willReturn($guest);
        $this->conflictChecker
            ->method('assertAvailable')
            ->willThrowException(new ConflictException('Cette chambre est déjà réservée pour ces dates.'));

        $dto = new CreateReservationDTO();
        $dto->roomId   = (string) Uuid::v4();
        $dto->guestId  = (string) Uuid::v4();
        $dto->checkIn  = '2026-06-01';
        $dto->checkOut = '2026-06-04';

        $this->expectException(ConflictException::class);
        $this->engine->create($dto, $this->staff);
    }

    // ── Test 3 : Chambre introuvable ──

    public function testCreateReservationRoomNotFound(): void
    {
        $this->roomRepo->method('find')->willReturn(null);

        $dto = new CreateReservationDTO();
        $dto->roomId   = (string) Uuid::v4();
        $dto->guestId  = (string) Uuid::v4();
        $dto->checkIn  = '2026-06-01';
        $dto->checkOut = '2026-06-04';

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $this->engine->create($dto, $this->staff);
    }

    // ── Test 4 : Check-in ──

    public function testCheckInSetsRoomOccupied(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->expects($this->once())->method('setStatusEnum')->with(RoomStatus::OCCUPIED);

        $reservation = $this->makeReservation('confirmed', $room, $guest);

        // checkIn() ne crée plus de CleaningTask (DEPARTURE créée au check-out)
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->engine->checkIn($reservation, null, $this->staff);

        $this->assertEquals('checked_in', $result->getStatus());
        $this->assertNotNull($result->getCheckedInAt());
    }

    // ── Test 5 : Check-out ──

    public function testCheckOutSetsRoomCleaning(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->expects($this->once())->method('setStatusEnum')->with(RoomStatus::CLEANING);
        $guest->method('getTotalStays')->willReturn(2);
        $guest->expects($this->once())->method('setTotalStays')->with(3);

        $reservation = $this->makeReservation('checked_in', $room, $guest);

        // checkOut() crée une tâche DEPARTURE
        $this->cleaningTaskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(false);
        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->engine->checkOut($reservation, $this->staff);

        $this->assertEquals('checked_out', $result->getStatus());
        $this->assertNotNull($result->getCheckedOutAt());
    }

    // ── Test 6 : Annulation ──

    public function testCancelSetsStatusCancelled(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $reservation = $this->makeReservation('confirmed', $room, $guest);

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->engine->cancel($reservation, 'Client demande annulation', $this->staff);

        $this->assertEquals('cancelled', $result->getStatus());
        $this->assertStringContainsString('Client demande annulation', $result->getNotes());
    }

    // ── Test 6b : Annulation publie sur Mercure ──

    public function testCancelPublishesMercureEvent(): void
    {
        $room  = $this->makeRoom('205');
        $guest = $this->makeGuest();

        $reservation = $this->makeReservation('confirmed', $room, $guest);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'reservation.cancelled',
                $this->callback(function (array $data): bool {
                    return isset($data['id'], $data['confirmationNumber'], $data['room'], $data['reason'])
                        && $data['room'] === '205'
                        && $data['reason'] === 'Annulation test';
                }),
            );

        $this->engine->cancel($reservation, 'Annulation test', $this->staff);
    }

    // ── Test 7 : Check-out génère une facture draft ──

    public function testCheckOutTriggersInvoiceDraft(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->method('setStatusEnum');
        $guest->method('getTotalStays')->willReturn(0);
        $guest->method('setTotalStays');

        $reservation = $this->makeReservation('checked_in', $room, $guest);

        $this->cleaningTaskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(false);

        $this->invoiceDraftService
            ->expects($this->once())
            ->method('createFromReservation')
            ->with($reservation);

        $this->engine->checkOut($reservation, $this->staff);
    }

    // ── Test 8 : Check-out ne bloque pas si la facture échoue ──

    public function testCheckOutContinuesIfInvoiceFails(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->method('setStatusEnum');
        $guest->method('getTotalStays')->willReturn(1);
        $guest->method('setTotalStays');

        $reservation = $this->makeReservation('checked_in', $room, $guest);

        $this->cleaningTaskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(false);

        $this->invoiceDraftService
            ->method('createFromReservation')
            ->willThrowException(new \RuntimeException('DB down'));

        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('facture draft'));

        $result = $this->engine->checkOut($reservation, $this->staff);

        $this->assertEquals('checked_out', $result->getStatus());
    }

    // ── Test 9 : Calcul du total ──

    public function testTotalCalculation(): void
    {
        $room  = $this->makeRoom('205', '55000.00');
        $guest = $this->makeGuest('Fatou', 'Ndiaye');

        $this->roomRepo->method('find')->willReturn($room);
        $this->guestRepo->method('find')->willReturn($guest);
        $this->conflictChecker->expects($this->once())->method('assertAvailable');
        $this->reservationRepo->method('generateConfirmationNumber')->willReturn('RES-2026-00100');
        $this->entityManager->expects($this->once())->method('persist')
            ->willReturnCallback(function (object $entity) {
                if ($entity instanceof Reservation) {
                    $ref = new \ReflectionProperty(Reservation::class, 'id');
                    $ref->setValue($entity, Uuid::v4());
                }
            });
        $this->entityManager->expects($this->once())->method('flush');

        $dto = new CreateReservationDTO();
        $dto->roomId   = (string) Uuid::v4();
        $dto->guestId  = (string) Uuid::v4();
        $dto->checkIn  = '2026-07-10';
        $dto->checkOut = '2026-07-15'; // 5 nuits

        $reservation = $this->engine->create($dto, $this->staff);

        // 5 nuits × 55 000 = 275 000
        $this->assertEquals('275000.00', $reservation->getTotalXof());
        $this->assertEquals('55000.00', $reservation->getRateXof());
    }

    // ── Test 10 : Check-out crée une tâche DEPARTURE ──

    public function testCheckOutCreatesDepartureTask(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->method('setStatusEnum');
        $guest->method('getTotalStays')->willReturn(0);
        $guest->method('setTotalStays');

        $reservation = $this->makeReservation('checked_in', $room, $guest);

        $this->cleaningTaskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(false);

        $this->entityManager->expects($this->once())->method('persist')
            ->with($this->callback(function (object $entity): bool {
                return $entity instanceof CleaningTask
                    && $entity->getType() === 'departure';
            }));

        $this->engine->checkOut($reservation, $this->staff);
    }

    // ── Test 11 : Check-out ne duplique pas la tâche si elle existe déjà ──

    public function testCheckOutSkipsDepartureTaskIfAlreadyExists(): void
    {
        $room  = $this->makeRoom();
        $guest = $this->makeGuest();

        $room->method('setStatusEnum');
        $guest->method('getTotalStays')->willReturn(0);
        $guest->method('setTotalStays');

        $reservation = $this->makeReservation('checked_in', $room, $guest);

        // Tâche active existe déjà (ex : recouche générée le matin)
        $this->cleaningTaskRepo->method('hasActiveTaskForRoomOnDate')->willReturn(true);

        $this->entityManager->expects($this->never())->method('persist');

        $this->engine->checkOut($reservation, $this->staff);
    }
}
