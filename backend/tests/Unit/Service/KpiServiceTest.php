<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Analytics\Domain\DTO\DashboardKpis;
use App\Hotel\Analytics\Domain\DTO\PeriodReport;
use App\Hotel\Analytics\Domain\Service\KpiService;
use App\Hotel\Analytics\Infrastructure\Repository\AnalyticsRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Room\Domain\Entity\Room;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\TenantContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Uid\Uuid;

class KpiServiceTest extends TestCase
{
    private KpiService $kpiService;
    private MockObject&AnalyticsRepository $analyticsRepo;

    protected function setUp(): void
    {
        $this->analyticsRepo = $this->createMock(AnalyticsRepository::class);
        $this->kpiService    = new KpiService($this->analyticsRepo);
    }

    // ── Helpers ──

    private function makeReservation(
        string $checkIn,
        string $checkOut,
        string $totalXof,
        string $status = 'confirmed',
    ): Reservation {
        $reservation = new Reservation();
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, Uuid::v4());

        $reservation->setCheckIn(new \DateTimeImmutable($checkIn));
        $reservation->setCheckOut(new \DateTimeImmutable($checkOut));
        $reservation->setTotalXof($totalXof);
        $reservation->setRateXof(
            bcdiv($totalXof, (string) $reservation->getNights(), 2),
        );
        $reservation->setStatus($status);

        $room = new Room();
        $roomRef = new \ReflectionProperty(Room::class, 'id');
        $roomRef->setValue($room, Uuid::v4());
        $reservation->setRoom($room);

        return $reservation;
    }

    // ══════════════════════════════════════════════════════════
    //  nightsInPeriod — formule d'intersection
    // ══════════════════════════════════════════════════════════

    public function testNightsInPeriodFullOverlap(): void
    {
        // Résa 01/06 → 04/06 (3 nuits), période 01/06 → 03/06 (3 jours inclusifs)
        $r = $this->makeReservation('2026-06-01', '2026-06-04', '135000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
        );

        $this->assertEquals(3, $nights);
    }

    public function testNightsInPeriodPartialOverlapLeft(): void
    {
        // Résa 28/05 → 02/06 (5 nuits), période 01/06 → 30/06
        // Nuits dans la période : seulement la nuit du 01/06 (checkOut 02 = fin)
        $r = $this->makeReservation('2026-05-28', '2026-06-02', '250000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(1, $nights);
    }

    public function testNightsInPeriodPartialOverlapRight(): void
    {
        // Résa 28/06 → 03/07 (5 nuits), période 01/06 → 30/06
        // Nuits dans la période : 28, 29, 30 = 3 nuits
        // (nuit du 30 est la dernière dans la période, checkOut 03/07 > 01/07)
        $r = $this->makeReservation('2026-06-28', '2026-07-03', '250000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(3, $nights);
    }

    public function testNightsInPeriodNoOverlap(): void
    {
        // Résa 01/05 → 04/05, période 01/06 → 30/06
        $r = $this->makeReservation('2026-05-01', '2026-05-04', '135000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(0, $nights);
    }

    public function testNightsInPeriodCheckOutEqualsFrom(): void
    {
        // Résa 28/05 → 01/06, période 01/06 → 30/06
        // Le checkOut est le 01/06 : la dernière nuit est le 31/05 → pas dans la période
        $r = $this->makeReservation('2026-05-28', '2026-06-01', '200000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(0, $nights);
    }

    public function testNightsInPeriodSingleDay(): void
    {
        // Résa 15/06 → 18/06 (3 nuits), période = jour unique 16/06
        // La nuit du 16 est couverte → 1 nuit
        $r = $this->makeReservation('2026-06-15', '2026-06-18', '135000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-16'),
            new \DateTimeImmutable('2026-06-16'),
        );

        $this->assertEquals(1, $nights);
    }

    public function testNightsInPeriodCheckInEqualsTo(): void
    {
        // Résa checkIn = 30/06, période finit le 30/06 (inclusif)
        // La nuit du 30 est dans la période → 1 nuit
        $r = $this->makeReservation('2026-06-30', '2026-07-02', '90000.00');

        $nights = $this->kpiService->nightsInPeriod(
            $r,
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(1, $nights);
    }

    // ══════════════════════════════════════════════════════════
    //  Formules KPI — jeu de données connu
    // ══════════════════════════════════════════════════════════

    /**
     * Scénario contrôlé :
     * - 10 chambres actives (non hors-service)
     * - Période : 01/06 → 03/06 (3 jours inclusifs)
     * - Nuits disponibles = 10 × 3 = 30
     *
     * Réservations :
     *   R1: 01/06 → 04/06 (3 nuits, 135 000 TTC) → 3 nuits dans la période
     *   R2: 02/06 → 05/06 (3 nuits, 180 000 TTC) → 2 nuits dans la période
     *   R3: 31/05 → 02/06 (2 nuits, 90 000 TTC)  → 1 nuit dans la période
     *
     * Nuits vendues = 3 + 2 + 1 = 6
     *
     * Revenu TTC proratisé :
     *   R1: 135000 × 3/3 = 135000.00
     *   R2: 180000 × 2/3 = 120000.00
     *   R3: 90000  × 1/2 = 45000.00
     *   Total TTC = 300000.00
     *
     * Revenu HT = TTC / 1.18 par résa proratisée :
     *   R1: 135000.00 / 1.18 = 114406.77
     *   R2: 120000.00 / 1.18 = 101694.91
     *   R3: 45000.00  / 1.18 = 38135.59
     *   Total HT = 254237.27
     *
     * Occupation = 6 / 30 × 100 = 20.00%
     * ADR HT = 254237.27 / 6 = 42372.87
     * RevPAR HT = 254237.27 / 30 = 8474.57
     *
     * Vérification croisée : ADR × occupation/100 = 42372.87 × 0.20 = 8474.574
     * (= RevPAR à l'arrondi bcmath scale 2 près)
     */
    public function testPeriodReportFormulaOnKnownData(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);

        $r1 = $this->makeReservation('2026-06-01', '2026-06-04', '135000.00');
        $r2 = $this->makeReservation('2026-06-02', '2026-06-05', '180000.00');
        $r3 = $this->makeReservation('2026-05-31', '2026-06-02', '90000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r1, $r2, $r3]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
        );

        $this->assertInstanceOf(PeriodReport::class, $report);

        // Nuits
        $this->assertEquals(30, $report->roomNightsAvailable);
        $this->assertEquals(6, $report->roomNightsSold);

        // Revenu TTC proratisé
        $this->assertEquals('300000.00', $report->roomRevenueTtc);

        // Revenu HT
        $this->assertEquals('254237.27', $report->roomRevenueHt);

        // Taux d'occupation
        $this->assertEquals('20.00', $report->occupancyRate);

        // ADR HT
        $this->assertEquals('42372.87', $report->adrHt);

        // RevPAR HT
        $this->assertEquals('8474.57', $report->revparHt);
    }

    /**
     * Vérification croisée : RevPAR == ADR × (occupancyRate / 100)
     * à l'arrondi bcmath scale 2 près.
     */
    public function testRevparCrossCheckIdentity(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);

        $r1 = $this->makeReservation('2026-06-01', '2026-06-04', '135000.00');
        $r2 = $this->makeReservation('2026-06-02', '2026-06-05', '180000.00');
        $r3 = $this->makeReservation('2026-05-31', '2026-06-02', '90000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r1, $r2, $r3]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
        );

        // RevPAR = ADR × (occupancy / 100)
        $expected = bcdiv(
            bcmul($report->adrHt, $report->occupancyRate, 4),
            '100',
            2,
        );

        // Tolérance de 0.01 XOF due aux arrondis intermédiaires
        $diff = bcsub($report->revparHt, $expected, 2);
        $absDiff = bccomp($diff, '0', 2) < 0 ? bcmul($diff, '-1', 2) : $diff;

        $this->assertTrue(
            bccomp($absDiff, '0.02', 2) <= 0,
            "RevPAR ({$report->revparHt}) != ADR ({$report->adrHt}) × occupation ({$report->occupancyRate}%) = {$expected} (diff: {$diff})",
        );
    }

    // ══════════════════════════════════════════════════════════
    //  Cas limites
    // ══════════════════════════════════════════════════════════

    public function testZeroRoomsReturnsSafeDefaults(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(0);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
        );

        $this->assertEquals('0.00', $report->occupancyRate);
        $this->assertEquals('0.00', $report->adrHt);
        $this->assertEquals('0.00', $report->revparHt);
        $this->assertEquals(0, $report->roomNightsAvailable);
        $this->assertEquals(0, $report->roomNightsSold);
    }

    public function testZeroReservationsAllZeros(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(15);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-30'),
        );

        $this->assertEquals(15 * 30, $report->roomNightsAvailable);
        $this->assertEquals(0, $report->roomNightsSold);
        $this->assertEquals('0.00', $report->occupancyRate);
        $this->assertEquals('0.00', $report->adrHt);
        $this->assertEquals('0.00', $report->revparHt);
        $this->assertEquals('0.00', $report->roomRevenueHt);
    }

    public function testSingleDayPeriod(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(5);

        // Résa couvre le jour
        $r = $this->makeReservation('2026-06-10', '2026-06-13', '150000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-11'),
            new \DateTimeImmutable('2026-06-11'),
        );

        // 1 jour, 5 chambres → 5 nuits disponibles
        $this->assertEquals(5, $report->roomNightsAvailable);
        // 1 nuit vendue (la nuit du 11)
        $this->assertEquals(1, $report->roomNightsSold);

        // Revenu proratisé : 150000 × 1/3 = 50000 TTC
        $this->assertEquals('50000.00', $report->roomRevenueTtc);
        // HT : 50000 / 1.18 = 42372.88
        $this->assertEquals('42372.88', $report->roomRevenueHt);

        // Occupation : 1/5 × 100 = 20
        $this->assertEquals('20.00', $report->occupancyRate);
    }

    // ══════════════════════════════════════════════════════════
    //  Série journalière
    // ══════════════════════════════════════════════════════════

    public function testDailySeriesHasCorrectLength(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-07'),
        );

        $this->assertCount(7, $report->dailySeries);
        $this->assertEquals('2026-06-01', $report->dailySeries[0]->date);
        $this->assertEquals('2026-06-07', $report->dailySeries[6]->date);
    }

    public function testDailySeriesValuesMatchAggregates(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);

        $r = $this->makeReservation('2026-06-02', '2026-06-04', '90000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-05'),
        );

        // 5 jours, résa couvre nuits du 02 et du 03 = 2 nuits vendues
        $this->assertCount(5, $report->dailySeries);

        // Jour 01 : pas de nuit → 0
        $this->assertEquals(0, $report->dailySeries[0]->soldNights);
        // Jour 02 : 1 nuit
        $this->assertEquals(1, $report->dailySeries[1]->soldNights);
        // Jour 03 : 1 nuit
        $this->assertEquals(1, $report->dailySeries[2]->soldNights);
        // Jour 04 : checkOut, pas de nuit
        $this->assertEquals(0, $report->dailySeries[3]->soldNights);
        // Jour 05 : 0
        $this->assertEquals(0, $report->dailySeries[4]->soldNights);

        // Somme des nuits vendues dans la série = total agrégé
        $totalSold = array_sum(array_map(fn($p) => $p->soldNights, $report->dailySeries));
        $this->assertEquals($report->roomNightsSold, $totalSold);
    }

    // ══════════════════════════════════════════════════════════
    //  Dashboard today
    // ══════════════════════════════════════════════════════════

    public function testDashboardTodayReturnsCorrectStructure(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);
        $this->analyticsRepo->method('arrivalsForDay')->willReturn([]);
        $this->analyticsRepo->method('departuresForDay')->willReturn([]);

        $kpis = $this->kpiService->dashboardToday();

        $this->assertInstanceOf(DashboardKpis::class, $kpis);

        $arr = $kpis->toArray();
        $this->assertArrayHasKey('occupancyRate', $arr);
        $this->assertArrayHasKey('adrHt', $arr);
        $this->assertArrayHasKey('revparHt', $arr);
        $this->assertArrayHasKey('roomRevenueHt', $arr);
        $this->assertArrayHasKey('roomRevenueTtc', $arr);
        $this->assertArrayHasKey('roomNightsAvailable', $arr);
        $this->assertArrayHasKey('roomNightsSold', $arr);
        $this->assertArrayHasKey('arrivalsToday', $arr);
        $this->assertArrayHasKey('departuresToday', $arr);
        $this->assertArrayHasKey('occupiedRooms', $arr);
        $this->assertArrayHasKey('availableRooms', $arr);
    }

    public function testDashboardTodayCountsArrivalsAndDepartures(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);

        $a1 = $this->makeReservation('2026-06-01', '2026-06-03', '90000.00');
        $a2 = $this->makeReservation('2026-06-01', '2026-06-04', '135000.00');
        $this->analyticsRepo->method('arrivalsForDay')->willReturn([$a1, $a2]);

        $d1 = $this->makeReservation('2026-05-29', '2026-06-01', '135000.00', 'checked_in');
        $this->analyticsRepo->method('departuresForDay')->willReturn([$d1]);

        $kpis = $this->kpiService->dashboardToday();

        $this->assertEquals(2, $kpis->arrivalsToday);
        $this->assertEquals(1, $kpis->departuresToday);
    }

    public function testDashboardTodayCountsOccupiedRooms(): void
    {
        $tz    = new \DateTimeZone('Africa/Dakar');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow', $tz))->format('Y-m-d');
        $afterTomorrow = (new \DateTimeImmutable('+2 days', $tz))->format('Y-m-d');

        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);
        $this->analyticsRepo->method('arrivalsForDay')->willReturn([]);
        $this->analyticsRepo->method('departuresForDay')->willReturn([]);

        // 2 réservations checked_in couvrant aujourd'hui
        $r1 = $this->makeReservation($today, $afterTomorrow, '90000.00', 'checked_in');
        $r2 = $this->makeReservation($today, $tomorrow, '45000.00', 'checked_in');
        // 1 réservation confirmed (pas comptée comme occupée)
        $r3 = $this->makeReservation($today, $afterTomorrow, '90000.00', 'confirmed');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r1, $r2, $r3]);

        $kpis = $this->kpiService->dashboardToday();

        $this->assertEquals(2, $kpis->occupiedRooms);
    }

    // ══════════════════════════════════════════════════════════
    //  Prorata revenu
    // ══════════════════════════════════════════════════════════

    public function testRevenueProrata(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(10);

        // Résa 5 nuits, 250 000 TTC. Période = 2 jours qui couvrent 2 nuits
        // Prorata TTC = 250000 × 2/5 = 100000
        // Prorata HT  = 100000 / 1.18 = 84745.76
        $r = $this->makeReservation('2026-06-01', '2026-06-06', '250000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-03'),
            new \DateTimeImmutable('2026-06-04'),
        );

        // 2 nuits dans la période (nuits du 03 et du 04)
        $this->assertEquals(2, $report->roomNightsSold);
        $this->assertEquals('100000.00', $report->roomRevenueTtc);
        $this->assertEquals('84745.76', $report->roomRevenueHt);
    }

    // ══════════════════════════════════════════════════════════
    //  Occupation 100%
    // ══════════════════════════════════════════════════════════

    public function testFullOccupancy(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(2);

        // 2 chambres, 1 jour. 2 résas couvrant le jour.
        $r1 = $this->makeReservation('2026-06-10', '2026-06-11', '50000.00');
        $r2 = $this->makeReservation('2026-06-10', '2026-06-11', '60000.00');

        $this->analyticsRepo->method('reservationsIntersectingPeriod')
            ->willReturn([$r1, $r2]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-10'),
            new \DateTimeImmutable('2026-06-10'),
        );

        $this->assertEquals(2, $report->roomNightsAvailable);
        $this->assertEquals(2, $report->roomNightsSold);
        $this->assertEquals('100.00', $report->occupancyRate);
    }

    // ══════════════════════════════════════════════════════════
    //  DTO toArray
    // ══════════════════════════════════════════════════════════

    public function testPeriodReportToArrayIncludesDailySeries(): void
    {
        $this->analyticsRepo->method('countActiveAvailableRooms')->willReturn(5);
        $this->analyticsRepo->method('reservationsIntersectingPeriod')->willReturn([]);

        $report = $this->kpiService->periodReport(
            new \DateTimeImmutable('2026-06-01'),
            new \DateTimeImmutable('2026-06-03'),
        );

        $arr = $report->toArray();
        $this->assertArrayHasKey('dailySeries', $arr);
        $this->assertCount(3, $arr['dailySeries']);
        $this->assertArrayHasKey('date', $arr['dailySeries'][0]);
        $this->assertArrayHasKey('occupancyRate', $arr['dailySeries'][0]);
        $this->assertArrayHasKey('roomRevenueHt', $arr['dailySeries'][0]);
        $this->assertArrayHasKey('soldNights', $arr['dailySeries'][0]);
        $this->assertEquals('2026-06-01', $arr['from']);
        $this->assertEquals('2026-06-03', $arr['to']);
    }

    // ══════════════════════════════════════════════════════════
    //  Sprint 14-B.2.2 — Cache KPI dashboard (kpi.cache)
    // ══════════════════════════════════════════════════════════

    /**
     * Helper : fabrique un Tenant avec un UUID donné (réflexion sur la
     * propriété `id` car le setter privé n'est exposé que via le
     * provisioning).
     */
    private function makeTenantWithId(Uuid $uuid): Tenant
    {
        $tenant = new Tenant();
        $ref = new \ReflectionProperty(Tenant::class, 'id');
        $ref->setValue($tenant, $uuid);

        return $tenant;
    }

    /**
     * Helper : nouveau KpiService avec un repo mock dédié, un
     * ArrayAdapter et un TenantContext rempli avec le tenant donné.
     *
     * @return array{KpiService, MockObject&AnalyticsRepository, ArrayAdapter}
     */
    private function makeCachedService(Tenant $tenant): array
    {
        $repo = $this->createMock(AnalyticsRepository::class);
        $pool = new ArrayAdapter();

        $tenantContext = new TenantContext();
        $tenantContext->set($tenant);

        $service = new KpiService($repo, $pool, $tenantContext);

        return [$service, $repo, $pool];
    }

    public function testDashboardForDateCachedHitsCacheOnSecondCall(): void
    {
        $tenant = $this->makeTenantWithId(Uuid::v4());
        [$service, $repo] = $this->makeCachedService($tenant);

        // Repo doit être appelé exactement UNE fois, pas deux.
        $repo->expects($this->once())->method('countActiveAvailableRooms')->willReturn(10);
        $repo->expects($this->once())->method('reservationsIntersectingPeriod')->willReturn([]);
        $repo->expects($this->once())->method('arrivalsForDay')->willReturn([]);
        $repo->expects($this->once())->method('departuresForDay')->willReturn([]);

        $tz    = new \DateTimeZone('Africa/Dakar');
        $today = new \DateTimeImmutable('today', $tz);

        $k1 = $service->dashboardForDateCached($today);
        $k2 = $service->dashboardForDateCached($today);

        $this->assertInstanceOf(DashboardKpis::class, $k1);
        $this->assertEquals($k1->toArray(), $k2->toArray());
    }

    public function testCacheKeyIsScopedByTenant(): void
    {
        // Deux tenants distincts → deux clés distinctes → AnalyticsRepo
        // mock distinct appelé une fois par tenant (pas de collision).
        $tenantA = $this->makeTenantWithId(Uuid::v4());
        $tenantB = $this->makeTenantWithId(Uuid::v4());

        [$serviceA, $repoA] = $this->makeCachedService($tenantA);
        [$serviceB, $repoB] = $this->makeCachedService($tenantB);

        $repoA->expects($this->once())->method('countActiveAvailableRooms')->willReturn(10);
        $repoA->expects($this->once())->method('reservationsIntersectingPeriod')->willReturn([]);
        $repoA->expects($this->once())->method('arrivalsForDay')->willReturn([]);
        $repoA->expects($this->once())->method('departuresForDay')->willReturn([]);

        $repoB->expects($this->once())->method('countActiveAvailableRooms')->willReturn(20);
        $repoB->expects($this->once())->method('reservationsIntersectingPeriod')->willReturn([]);
        $repoB->expects($this->once())->method('arrivalsForDay')->willReturn([]);
        $repoB->expects($this->once())->method('departuresForDay')->willReturn([]);

        $tz    = new \DateTimeZone('Africa/Dakar');
        $today = new \DateTimeImmutable('today', $tz);

        $kA = $serviceA->dashboardForDateCached($today);
        $kB = $serviceB->dashboardForDateCached($today);

        // Pas de leak : chaque tenant voit ses propres chiffres.
        $this->assertSame(10, $kA->availableRooms);
        $this->assertSame(20, $kB->availableRooms);
    }

    public function testInvalidateDashboardCacheForcesRecompute(): void
    {
        $tenant = $this->makeTenantWithId(Uuid::v4());
        [$service, $repo] = $this->makeCachedService($tenant);

        // Repo appelé exactement 2 fois : 1 fois avant invalidation
        // (warm-up), 1 fois après invalidation (re-warm).
        $repo->expects($this->exactly(2))->method('countActiveAvailableRooms')->willReturn(10);
        $repo->expects($this->exactly(2))->method('reservationsIntersectingPeriod')->willReturn([]);
        $repo->expects($this->exactly(2))->method('arrivalsForDay')->willReturn([]);
        $repo->expects($this->exactly(2))->method('departuresForDay')->willReturn([]);

        $tz    = new \DateTimeZone('Africa/Dakar');
        $today = new \DateTimeImmutable('today', $tz);

        $service->dashboardForDateCached($today); // warm
        $service->dashboardForDateCached($today); // hit

        $service->invalidateDashboardCache($today);

        $service->dashboardForDateCached($today); // miss → recompute
    }

    public function testPastDateUsesLongTtlAndStoresEntry(): void
    {
        $tenant = $this->makeTenantWithId(Uuid::v4());
        [$service, $repo, $pool] = $this->makeCachedService($tenant);

        $repo->expects($this->once())->method('countActiveAvailableRooms')->willReturn(10);
        $repo->expects($this->once())->method('reservationsIntersectingPeriod')->willReturn([]);
        $repo->expects($this->once())->method('arrivalsForDay')->willReturn([]);
        $repo->expects($this->once())->method('departuresForDay')->willReturn([]);

        $tz    = new \DateTimeZone('Africa/Dakar');
        $past  = (new \DateTimeImmutable('today', $tz))->modify('-7 days');

        $service->dashboardForDateCached($past);

        // L'entrée doit être présente sous la clé tenant-scoped.
        $key  = sprintf('kpi_dashboard_%s_%s', (string) $tenant->getId(), $past->setTime(0, 0)->format('Y-m-d'));
        $item = $pool->getItem($key);

        $this->assertTrue($item->isHit(), 'L\'entrée de cache doit avoir été persistée pour une date passée');
        $this->assertInstanceOf(DashboardKpis::class, $item->get());
    }

    public function testFutureDateBypassesCache(): void
    {
        $tenant = $this->makeTenantWithId(Uuid::v4());
        [$service, $repo, $pool] = $this->makeCachedService($tenant);

        // Deux appels successifs sur une date future → repo appelé 2x
        // (pas de cache).
        $repo->expects($this->exactly(2))->method('countActiveAvailableRooms')->willReturn(10);
        $repo->expects($this->exactly(2))->method('reservationsIntersectingPeriod')->willReturn([]);
        $repo->expects($this->exactly(2))->method('arrivalsForDay')->willReturn([]);
        $repo->expects($this->exactly(2))->method('departuresForDay')->willReturn([]);

        $tz     = new \DateTimeZone('Africa/Dakar');
        $future = (new \DateTimeImmutable('today', $tz))->modify('+7 days');

        $service->dashboardForDateCached($future);
        $service->dashboardForDateCached($future);

        // Rien n'a été stocké sous la clé du tenant.
        $key  = sprintf('kpi_dashboard_%s_%s', (string) $tenant->getId(), $future->setTime(0, 0)->format('Y-m-d'));
        $item = $pool->getItem($key);
        $this->assertFalse($item->isHit(), 'Aucune entrée de cache pour une date future');
    }
}
