<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Service\InvoiceDraftService;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\Entity\Promotion;
use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use App\Hotel\Rate\Domain\Enum\PromotionType;
use App\Hotel\Rate\Domain\Enum\SeasonalRateType;
use App\Hotel\Rate\Domain\Service\PriceCalculator;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use App\Hotel\Rate\Infrastructure\Repository\RatePlanRepository;
use App\Hotel\Rate\Infrastructure\Repository\SeasonalRateRepository;
use App\Hotel\Reservation\Application\DTO\CreateReservationDTO;
use App\Hotel\Reservation\Application\DTO\UpdateReservationDTO;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Domain\Service\ConflictChecker;
use App\Hotel\Reservation\Domain\Service\ReservationEngine;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tests d'intégration PriceCalculator ↔ ReservationEngine ↔ InvoiceDraftService.
 */
class ReservationPricingTest extends TestCase
{
    private ReservationEngine $engine;
    private MockObject&SeasonalRateRepository $seasonalRepo;
    private MockObject&PromotionRepository $promotionRepo;
    private MockObject&RatePlanRepository $ratePlanRepo;
    private MockObject&ReservationRepository $reservationRepo;
    private MockObject&RoomRepository $roomRepo;
    private MockObject&GuestRepository $guestRepo;
    private MockObject&EntityManagerInterface $entityManager;
    private MockObject&InvoiceDraftService $invoiceDraftService;
    private MockObject&StaffUser $staff;
    private Uuid $hotelId;

    protected function setUp(): void
    {
        $this->seasonalRepo  = $this->createMock(SeasonalRateRepository::class);
        $this->promotionRepo = $this->createMock(PromotionRepository::class);
        $this->ratePlanRepo  = $this->createMock(RatePlanRepository::class);
        $this->reservationRepo = $this->createMock(ReservationRepository::class);
        $this->roomRepo      = $this->createMock(RoomRepository::class);
        $this->guestRepo     = $this->createMock(GuestRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->invoiceDraftService = $this->createMock(InvoiceDraftService::class);
        $this->staff         = $this->createMock(StaffUser::class);

        $this->hotelId = Uuid::v4();

        // Stub HotelProfile repository
        $hotelProfile = $this->createMock(HotelProfile::class);
        $hotelProfile->method('getId')->willReturn($this->hotelId);
        $hotelProfileRepo = $this->createMock(EntityRepository::class);
        $hotelProfileRepo->method('findOneBy')->willReturn($hotelProfile);
        $this->entityManager->method('getRepository')
            ->with(HotelProfile::class)
            ->willReturn($hotelProfileRepo);

        $priceCalculator = new PriceCalculator($this->seasonalRepo, $this->promotionRepo);

        $this->engine = new ReservationEngine(
            $this->reservationRepo,
            $this->roomRepo,
            $this->guestRepo,
            $this->createMock(ConflictChecker::class),
            $this->createMock(AuditService::class),
            $this->createMock(MercurePublisher::class),
            $this->invoiceDraftService,
            $this->createMock(CleaningTaskRepository::class),
            $this->createMock(LoggerInterface::class),
            $this->entityManager,
            $priceCalculator,
            $this->ratePlanRepo,
            $this->promotionRepo,
        );
    }

    private function makeRoom(string $baseRate = '45000.00'): Room
    {
        $roomTypeId = Uuid::v4();
        $roomType = $this->createMock(RoomType::class);
        $roomType->method('getId')->willReturn($roomTypeId);
        $roomType->method('getBaseRateXof')->willReturn($baseRate);

        $room = $this->createMock(Room::class);
        $room->method('getId')->willReturn(Uuid::v4());
        $room->method('getNumber')->willReturn('312');
        $room->method('getType')->willReturn($roomType);

        return $room;
    }

    private function makeGuest(): Guest
    {
        $guest = $this->createMock(Guest::class);
        $guest->method('getId')->willReturn(Uuid::v4());
        $guest->method('getFullName')->willReturn('Amadou Diallo');

        return $guest;
    }

    private function makeSeasonalRate(string $name, SeasonalRateType $type, string $value, string $from, string $to, int $priority): SeasonalRate
    {
        $sr = new SeasonalRate();
        $ref = new \ReflectionProperty(SeasonalRate::class, 'id');
        $ref->setValue($sr, Uuid::v4());

        $sr->setName($name)
            ->setTypeEnum($type)
            ->setValue($value)
            ->setStartDate(new \DateTimeImmutable($from))
            ->setEndDate(new \DateTimeImmutable($to))
            ->setPriority($priority)
            ->setIsActive(true);

        return $sr;
    }

    private function makePromotion(string $code, PromotionType $type, string $value, int $minNights = 1, ?string $maxDiscount = null): Promotion
    {
        $promo = new Promotion();
        $ref = new \ReflectionProperty(Promotion::class, 'id');
        $ref->setValue($promo, Uuid::v4());

        $hotel = $this->createMock(HotelProfile::class);
        $hotel->method('getId')->willReturn($this->hotelId);

        $promo->setHotel($hotel)
            ->setCode($code)
            ->setTypeEnum($type)
            ->setValue($value)
            ->setMinNights($minNights)
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setValidTo(new \DateTimeImmutable('2026-12-31'))
            ->setIsActive(true);

        if ($maxDiscount !== null) {
            $promo->setMaxDiscountXof($maxDiscount);
        }

        return $promo;
    }

    private function makeDto(string $checkIn = '2026-06-01', string $checkOut = '2026-06-04', ?string $promoCode = null): CreateReservationDTO
    {
        $dto = new CreateReservationDTO();
        $dto->roomId   = (string) Uuid::v4();
        $dto->guestId  = (string) Uuid::v4();
        $dto->checkIn  = $checkIn;
        $dto->checkOut = $checkOut;
        $dto->adults   = 2;
        $dto->promoCode = $promoCode;

        return $dto;
    }

    private function setupStandardMocks(Room $room, Guest $guest): void
    {
        $this->roomRepo->method('find')->willReturn($room);
        $this->guestRepo->method('find')->willReturn($guest);
        $this->reservationRepo->method('generateConfirmationNumber')->willReturn('RES-2026-00100');
        $this->entityManager->method('persist')
            ->willReturnCallback(function (object $entity) {
                if ($entity instanceof Reservation) {
                    $ref = new \ReflectionProperty(Reservation::class, 'id');
                    $ref->setValue($entity, Uuid::v4());
                }
            });
    }

    // ── Test 1 : Seasonal MULTIPLIER → total majoré, priceBreakdown non null ──

    public function testCreateWithSeasonalRateStoresRealTotal(): void
    {
        $room  = $this->makeRoom('45000.00');
        $guest = $this->makeGuest();
        $this->setupStandardMocks($room, $guest);

        // Saison ×1.5 couvrant toute la période (01→03 juin)
        $season = $this->makeSeasonalRate('Haute', SeasonalRateType::MULTIPLIER, '1.50', '2026-06-01', '2026-06-03', 10);

        $this->seasonalRepo->method('findActiveForDate')->willReturn([$season]);

        $dto = $this->makeDto('2026-06-01', '2026-06-04'); // 3 nuits
        $reservation = $this->engine->create($dto, $this->staff);

        // 3 nuits × 45000 × 1.5 = 202500
        $this->assertEquals('202500.00', $reservation->getTotalXof());
        $this->assertEquals('45000.00', $reservation->getRateXof());
        $this->assertNotNull($reservation->getPriceBreakdown());
        $this->assertEquals('Haute', $reservation->getPriceBreakdown()['appliedSeasonalRateName']);
    }

    // ── Test 2 : Promo → usedCount incrémenté + total réduit ──

    public function testCreateWithPromoIncrementsUsedCount(): void
    {
        $room  = $this->makeRoom('45000.00');
        $guest = $this->makeGuest();
        $this->setupStandardMocks($room, $guest);

        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $promo = $this->makePromotion('OUVERTURE', PromotionType::PERCENTAGE, '10.00', 1);
        $this->promotionRepo->method('findOneActiveByCode')->willReturn($promo);

        $this->assertEquals(0, $promo->getUsedCount());

        $dto = $this->makeDto('2026-06-01', '2026-06-04', 'OUVERTURE'); // 3 nuits
        $reservation = $this->engine->create($dto, $this->staff);

        // 3 × 45000 = 135000, discount 10% = 13500, total = 121500
        $this->assertEquals('121500.00', $reservation->getTotalXof());
        $this->assertEquals(1, $promo->getUsedCount());
        $this->assertEquals('OUVERTURE', $reservation->getPriceBreakdown()['appliedPromotionCode']);
    }

    // ── Test 3 : Code bidon → total plein, pas d'erreur ──

    public function testCreateWithInvalidPromoNoDiscount(): void
    {
        $room  = $this->makeRoom('45000.00');
        $guest = $this->makeGuest();
        $this->setupStandardMocks($room, $guest);

        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);
        $this->promotionRepo->method('findOneActiveByCode')->willReturn(null);

        $dto = $this->makeDto('2026-06-01', '2026-06-04', 'BIDON');
        $reservation = $this->engine->create($dto, $this->staff);

        // Pas de discount → 3 × 45000 = 135000
        $this->assertEquals('135000.00', $reservation->getTotalXof());
        $this->assertNull($reservation->getPriceBreakdown()['appliedPromotionCode']);
    }

    // ── Test 4 : Update recalcule le total ──

    public function testUpdateRecalculatesTotal(): void
    {
        $room  = $this->makeRoom('45000.00');
        $guest = $this->makeGuest();

        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        // Réservation existante : 3 nuits
        $reservation = new Reservation();
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, Uuid::v4());
        $reservation->setRoom($room);
        $reservation->setGuest($guest);
        $reservation->setCheckIn(new \DateTimeImmutable('2026-06-01'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-06-04'));
        $reservation->setRateXof('45000.00');
        $reservation->setTotalXof('135000.00');
        $reservation->setConfirmationNumber('RES-2026-00001');
        $reservation->setStatusEnum(ReservationStatus::CONFIRMED);

        // Update : étendre à 5 nuits
        $dto = new UpdateReservationDTO();
        $dto->checkOut = '2026-06-06';

        $result = $this->engine->update($reservation, $dto, $this->staff);

        // 5 × 45000 = 225000
        $this->assertEquals('225000.00', $result->getTotalXof());
        $this->assertNotNull($result->getPriceBreakdown());
        $this->assertEquals(5, $result->getPriceBreakdown()['nights']);
    }

    // ── Test 5 : Invoice total == reservation total (avec saison) ──

    public function testInvoiceMatchesReservationTotal(): void
    {
        // Simulate a reservation with seasonal pricing → totalXof = 202500
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $invoiceRepo->method('findOneBy')->willReturn(null);
        $invoiceRepo->method('generateInvoiceNumber')->willReturn('FAC-2026-00001');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');

        $invoiceService = new InvoiceDraftService($invoiceRepo, $em);

        $tz = new \DateTimeZone('Africa/Dakar');
        $room = $this->createMock(Room::class);
        $room->method('getNumber')->willReturn('312');

        $reservation = $this->createMock(Reservation::class);
        $reservation->method('getId')->willReturn(Uuid::v4());
        $reservation->method('getCheckIn')->willReturn(new \DateTimeImmutable('2026-06-01', $tz));
        $reservation->method('getCheckOut')->willReturn(new \DateTimeImmutable('2026-06-04', $tz));
        $reservation->method('getRateXof')->willReturn('45000.00');
        // Source de vérité : totalXof includes seasonal adjustment
        $reservation->method('getTotalXof')->willReturn('202500.00');
        $reservation->method('getRoom')->willReturn($room);

        $invoice = $invoiceService->createFromReservation($reservation);

        // Invoice total MUST match reservation total (the bug we're fixing)
        $this->assertEquals('202500.00', $invoice->getTotalXof());
    }
}
