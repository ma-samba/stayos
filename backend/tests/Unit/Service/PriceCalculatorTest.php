<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Rate\Domain\DTO\PriceQuote;
use App\Hotel\Rate\Domain\Entity\Promotion;
use App\Hotel\Rate\Domain\Entity\RatePlan;
use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use App\Hotel\Rate\Domain\Enum\PromotionType;
use App\Hotel\Rate\Domain\Enum\SeasonalRateType;
use App\Hotel\Rate\Domain\Service\PriceCalculator;
use App\Hotel\Rate\Infrastructure\Repository\PromotionRepository;
use App\Hotel\Rate\Infrastructure\Repository\SeasonalRateRepository;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Shared\Exception\BusinessRuleException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class PriceCalculatorTest extends TestCase
{
    private PriceCalculator $calculator;
    private MockObject&SeasonalRateRepository $seasonalRepo;
    private MockObject&PromotionRepository $promoRepo;

    private Uuid $hotelId;
    private RoomType $roomType;
    private Uuid $roomTypeId;

    protected function setUp(): void
    {
        $this->seasonalRepo = $this->createMock(SeasonalRateRepository::class);
        $this->promoRepo    = $this->createMock(PromotionRepository::class);

        $this->calculator = new PriceCalculator(
            $this->seasonalRepo,
            $this->promoRepo,
        );

        $this->hotelId    = Uuid::v4();
        $this->roomTypeId = Uuid::v4();
        $this->roomType   = $this->makeRoomType('45000.00');
    }

    // ── Helpers ──

    private function makeRoomType(string $baseRate): RoomType
    {
        $roomType = $this->createMock(RoomType::class);
        $roomType->method('getId')->willReturn($this->roomTypeId);
        $roomType->method('getBaseRateXof')->willReturn($baseRate);

        return $roomType;
    }

    private function makeRatePlan(string $baseRate, bool $active = true, ?string $validFrom = null, ?string $validTo = null): RatePlan
    {
        $rp = new RatePlan();
        $ref = new \ReflectionProperty(RatePlan::class, 'id');
        $ref->setValue($rp, Uuid::v4());

        $rp->setBaseRateXof($baseRate);
        $rp->setIsActive($active);

        if ($validFrom !== null) {
            $rp->setValidFrom(new \DateTimeImmutable($validFrom));
        }
        if ($validTo !== null) {
            $rp->setValidTo(new \DateTimeImmutable($validTo));
        }

        return $rp;
    }

    private function makeSeasonalRate(
        string $name,
        SeasonalRateType $type,
        string $value,
        string $startDate,
        string $endDate,
        int $priority = 0,
    ): SeasonalRate {
        $sr = new SeasonalRate();
        $ref = new \ReflectionProperty(SeasonalRate::class, 'id');
        $ref->setValue($sr, Uuid::v4());

        // hotel mock
        $hotel = $this->createMock(\App\Hotel\Property\Domain\Entity\HotelProfile::class);
        $refH = new \ReflectionProperty(SeasonalRate::class, 'hotel');
        $refH->setValue($sr, $hotel);

        $sr->setName($name)
           ->setTypeEnum($type)
           ->setValue($value)
           ->setStartDate(new \DateTimeImmutable($startDate))
           ->setEndDate(new \DateTimeImmutable($endDate))
           ->setPriority($priority)
           ->setIsActive(true);

        return $sr;
    }

    private function makePromotion(
        PromotionType $type,
        string $value,
        string $code = 'PROMO',
        int $minNights = 1,
        ?string $maxDiscount = null,
        ?string $minAmount = null,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?int $maxUses = null,
        int $usedCount = 0,
        ?array $roomTypeIds = null,
        ?array $ratePlanIds = null,
    ): Promotion {
        $promo = new Promotion();
        $ref = new \ReflectionProperty(Promotion::class, 'id');
        $ref->setValue($promo, Uuid::v4());

        $hotel = $this->createMock(\App\Hotel\Property\Domain\Entity\HotelProfile::class);
        $refH = new \ReflectionProperty(Promotion::class, 'hotel');
        $refH->setValue($promo, $hotel);

        $promo->setCode($code)
              ->setTypeEnum($type)
              ->setValue($value)
              ->setMinNights($minNights)
              ->setMaxDiscountXof($maxDiscount)
              ->setMinAmountXof($minAmount)
              ->setMaxUses($maxUses)
              ->setUsedCount($usedCount)
              ->setIsActive(true)
              ->setApplicableRoomTypeIds($roomTypeIds)
              ->setApplicableRatePlanIds($ratePlanIds);

        if ($validFrom !== null) {
            $promo->setValidFrom(new \DateTimeImmutable($validFrom));
        }
        if ($validTo !== null) {
            $promo->setValidTo(new \DateTimeImmutable($validTo));
        }

        return $promo;
    }

    private function ci(string $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date, new \DateTimeZone('Africa/Dakar'));
    }

    // ── Test 1 : Tarif de base, pas de saison, pas de promo ──

    public function testBaseRateNoSeasonNoPromo(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'), // 3 nuits
        );

        $this->assertEquals('45000.00', $quote->baseRateXof);
        $this->assertEquals(3, $quote->nights);
        $this->assertEquals('135000.00', $quote->subtotalXof);
        $this->assertEquals('0.00', $quote->seasonalAdjustmentXof);
        $this->assertEquals('0.00', $quote->discountXof);
        $this->assertEquals('135000.00', $quote->totalXof);
        $this->assertNull($quote->appliedSeasonalRateName);
        $this->assertNull($quote->appliedPromotionCode);
    }

    // ── Test 2 : RatePlan remplace le tarif RoomType ──

    public function testRatePlanOverridesRoomType(): void
    {
        $ratePlan = $this->makeRatePlan('55000.00', true, '2026-01-01', '2026-12-31');
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            $ratePlan,
        );

        $this->assertEquals('55000.00', $quote->baseRateXof);
        $this->assertEquals('165000.00', $quote->totalXof);
    }

    // ── Test 3 : Saison MULTIPLIER appliquée ──

    public function testSeasonalMultiplierApplied(): void
    {
        $season = $this->makeSeasonalRate('Haute saison', SeasonalRateType::MULTIPLIER, '1.50', '2026-06-01', '2026-06-30', 10);

        $this->seasonalRepo->method('findActiveForDate')->willReturn([$season]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'), // 3 nuits
        );

        // 3 × 45000 × 1.50 = 202500
        $this->assertEquals('202500.00', $quote->subtotalXof);
        $this->assertEquals('67500.00', $quote->seasonalAdjustmentXof); // 202500 - 135000
        $this->assertEquals('202500.00', $quote->totalXof);
        $this->assertEquals('Haute saison', $quote->appliedSeasonalRateName);
    }

    // ── Test 4 : Saison ABSOLUTE appliquée ──

    public function testSeasonalAbsoluteApplied(): void
    {
        $season = $this->makeSeasonalRate('Tarif Magal', SeasonalRateType::ABSOLUTE, '75000.00', '2026-06-01', '2026-06-30', 20);

        $this->seasonalRepo->method('findActiveForDate')->willReturn([$season]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'), // 3 nuits
        );

        // 3 × 75000 = 225000
        $this->assertEquals('225000.00', $quote->subtotalXof);
        $this->assertEquals('90000.00', $quote->seasonalAdjustmentXof); // 225000 - 135000
        $this->assertEquals('Tarif Magal', $quote->appliedSeasonalRateName);
    }

    // ── Test 5 : Saison ne couvre que 2 nuits sur 4 ──

    public function testSeasonalPartialCoverage(): void
    {
        $season = $this->makeSeasonalRate('Haute saison', SeasonalRateType::MULTIPLIER, '2.00', '2026-06-01', '2026-06-02', 10);

        // Nuits : 1er (couvert), 2 (couvert), 3 (pas couvert), 4 (pas couvert)
        $this->seasonalRepo->method('findActiveForDate')
            ->willReturnCallback(function (Uuid $hotelId, ?Uuid $roomTypeId, \DateTimeImmutable $date) use ($season): array {
                $d = $date->format('Y-m-d');
                if ($d >= '2026-06-01' && $d <= '2026-06-02') {
                    return [$season];
                }
                return [];
            });

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-05'), // 4 nuits
        );

        // 2 nuits × 90000 + 2 nuits × 45000 = 180000 + 90000 = 270000
        $this->assertEquals('270000.00', $quote->subtotalXof);
        // adjustment = 270000 - (45000 × 4) = 270000 - 180000 = 90000
        $this->assertEquals('90000.00', $quote->seasonalAdjustmentXof);
    }

    // ── Test 6 : Priorité — la plus haute gagne ──

    public function testSeasonalPriorityWins(): void
    {
        $low  = $this->makeSeasonalRate('Basse saison', SeasonalRateType::MULTIPLIER, '0.80', '2026-06-01', '2026-06-30', 5);
        $high = $this->makeSeasonalRate('Haute saison', SeasonalRateType::MULTIPLIER, '1.50', '2026-06-01', '2026-06-30', 10);

        // Le repo retourne trié par priority DESC → high en premier
        $this->seasonalRepo->method('findActiveForDate')->willReturn([$high, $low]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-02'), // 1 nuit
        );

        // 45000 × 1.50 = 67500
        $this->assertEquals('67500.00', $quote->subtotalXof);
        $this->assertEquals('Haute saison', $quote->appliedSeasonalRateName);
    }

    // ── Test 7 : Promo PERCENTAGE réduit le total ──

    public function testPromoPercentageReducesTotal(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $promo = $this->makePromotion(PromotionType::PERCENTAGE, '10.00', 'PROMO10', validFrom: '2026-01-01', validTo: '2026-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'), // 3 nuits, subtotal 135000
            null,
            'PROMO10',
        );

        // 135000 × 10% = 13500
        $this->assertEquals('13500.00', $quote->discountXof);
        $this->assertEquals('121500.00', $quote->totalXof);
        $this->assertEquals('PROMO10', $quote->appliedPromotionCode);
    }

    // ── Test 8 : Promo FIXED réduit le total ──

    public function testPromoFixedReducesTotal(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $promo = $this->makePromotion(PromotionType::FIXED, '20000.00', 'FIXE20K', validFrom: '2026-01-01', validTo: '2026-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'FIXE20K',
        );

        $this->assertEquals('20000.00', $quote->discountXof);
        $this->assertEquals('115000.00', $quote->totalXof);
    }

    // ── Test 9 : Plafond maxDiscount respecté ──

    public function testPromoCapRespected(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        // 15% sur 135000 = 20250, mais plafond à 10000
        $promo = $this->makePromotion(PromotionType::PERCENTAGE, '15.00', 'CAP', maxDiscount: '10000.00', validFrom: '2026-01-01', validTo: '2026-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'CAP',
        );

        $this->assertEquals('10000.00', $quote->discountXof);
        $this->assertEquals('125000.00', $quote->totalXof);
    }

    // ── Test 10 : Promo expirée → ignorée (pas d'exception) ──

    public function testPromoExpiredIgnored(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $promo = $this->makePromotion(PromotionType::PERCENTAGE, '20.00', 'EXPIRED', validFrom: '2025-01-01', validTo: '2025-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'EXPIRED',
        );

        $this->assertEquals('0.00', $quote->discountXof);
        $this->assertEquals('135000.00', $quote->totalXof);
        $this->assertNull($quote->appliedPromotionCode);
    }

    // ── Test 11 : Promo minNights non atteint → ignorée ──

    public function testPromoMinNightsNotMet(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        // minNights = 5, mais séjour = 3
        $promo = $this->makePromotion(PromotionType::PERCENTAGE, '10.00', 'MIN5', minNights: 5, validFrom: '2026-01-01', validTo: '2026-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'MIN5',
        );

        $this->assertEquals('0.00', $quote->discountXof);
        $this->assertNull($quote->appliedPromotionCode);
    }

    // ── Test 12 : Promo restreinte à un autre roomType → ignorée ──

    public function testPromoRoomTypeRestriction(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $otherRoomTypeId = (string) Uuid::v4();
        $promo = $this->makePromotion(
            PromotionType::PERCENTAGE,
            '10.00',
            'RESTRICTED',
            roomTypeIds: [$otherRoomTypeId],
            validFrom: '2026-01-01',
            validTo: '2026-12-31',
        );
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'RESTRICTED',
        );

        $this->assertEquals('0.00', $quote->discountXof);
        $this->assertNull($quote->appliedPromotionCode);
    }

    // ── Test 13 : Code inexistant → ignoré, pas d'exception ──

    public function testInvalidCodeIgnoredNoException(): void
    {
        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);
        $this->promoRepo->method('findOneActiveByCode')->willReturn(null);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'INVALIDCODE',
        );

        $this->assertEquals('135000.00', $quote->totalXof);
        $this->assertEquals('0.00', $quote->discountXof);
        $this->assertNull($quote->appliedPromotionCode);
    }

    // ── Test 14 : Saison + Promo combinées (ordre correct) ──

    public function testSeasonAndPromoCombined(): void
    {
        // Saison ABSOLUTE 75000/nuit, 3 nuits = 225000
        $season = $this->makeSeasonalRate('Magal', SeasonalRateType::ABSOLUTE, '75000.00', '2026-06-01', '2026-06-30', 20);
        $this->seasonalRepo->method('findActiveForDate')->willReturn([$season]);

        // Promo 10% sur subtotal après saison
        $promo = $this->makePromotion(PromotionType::PERCENTAGE, '10.00', 'COMBO', validFrom: '2026-01-01', validTo: '2026-12-31');
        $this->promoRepo->method('findOneActiveByCode')->willReturn($promo);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-04'),
            null,
            'COMBO',
        );

        // subtotal = 225000, discount = 22500, total = 202500
        $this->assertEquals('225000.00', $quote->subtotalXof);
        $this->assertEquals('22500.00', $quote->discountXof);
        $this->assertEquals('202500.00', $quote->totalXof);
        $this->assertEquals('Magal', $quote->appliedSeasonalRateName);
        $this->assertEquals('COMBO', $quote->appliedPromotionCode);
    }

    // ── Test 15 : checkOut == checkIn → BusinessRuleException ──

    public function testOneNightMinimum(): void
    {
        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('au moins une nuit');

        $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            $this->ci('2026-06-01'),
            $this->ci('2026-06-01'), // 0 nuits
        );
    }

    // ── Test 16 : dates avec heures → normalisation à minuit ──

    public function testNightCountCorrectWhenDatesHaveTime(): void
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        $this->seasonalRepo->method('findActiveForDate')->willReturn([]);

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            new \DateTimeImmutable('2026-06-01 23:30:00', $tz),
            new \DateTimeImmutable('2026-06-04 08:00:00', $tz),
        );

        $this->assertEquals(3, $quote->nights);
        $this->assertEquals('135000.00', $quote->subtotalXof);
        $this->assertEquals('135000.00', $quote->totalXof);
    }

    // ── Test 17 : couverture saisonnière robuste aux heures ──

    public function testSeasonalCoverageRobustToTime(): void
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        $season = $this->makeSeasonalRate('Pointe', SeasonalRateType::MULTIPLIER, '1.50', '2026-06-01', '2026-06-01', 10);

        $this->seasonalRepo->method('findActiveForDate')->willReturnCallback(
            function (Uuid $hotelId, ?Uuid $roomTypeId, \DateTimeImmutable $date) use ($season): array {
                return $date->format('Y-m-d') === '2026-06-01' ? [$season] : [];
            }
        );

        $quote = $this->calculator->quote(
            $this->hotelId,
            $this->roomType,
            new \DateTimeImmutable('2026-06-01 23:30:00', $tz),
            new \DateTimeImmutable('2026-06-03 06:00:00', $tz),
        );

        // 2 nuits : nuit 01 = 45000 × 1.5 = 67500, nuit 02 = 45000
        $this->assertEquals(2, $quote->nights);
        $this->assertEquals('112500.00', $quote->subtotalXof);
        $this->assertEquals('112500.00', $quote->totalXof);
        $this->assertEquals('Pointe', $quote->appliedSeasonalRateName);
    }
}
