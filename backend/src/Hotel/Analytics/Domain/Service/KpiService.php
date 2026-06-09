<?php

namespace App\Hotel\Analytics\Domain\Service;

use App\Hotel\Analytics\Domain\DTO\DailyDataPoint;
use App\Hotel\Analytics\Domain\DTO\DashboardKpis;
use App\Hotel\Analytics\Domain\DTO\PeriodReport;
use App\Hotel\Analytics\Infrastructure\Repository\AnalyticsRepository;
use App\Hotel\Reservation\Domain\Entity\Reservation;

class KpiService
{
    private const TAX_DIVISOR = '1.18';

    public function __construct(
        private readonly AnalyticsRepository $analyticsRepository,
    ) {}

    /**
     * KPIs du jour (Africa/Dakar). Délégué à dashboardForDate(today).
     */
    public function dashboardToday(): DashboardKpis
    {
        $tz    = new \DateTimeZone('Africa/Dakar');
        $today = new \DateTimeImmutable('today', $tz);

        return $this->dashboardForDate($today);
    }

    /**
     * KPIs pour une date donnée. Mêmes calculs que dashboardToday mais
     * paramétrés — utilisé notamment par le night audit pour figer
     * un snapshot. Les `availableRooms` et `occupiedRooms` reflètent
     * l'état courant des chambres (V1 : pas de reconstruction
     * historique), ce qui est acceptable pour J ou J-1.
     */
    public function dashboardForDate(\DateTimeImmutable $date): DashboardKpis
    {
        $date = $date->setTime(0, 0);

        $availableRooms = $this->analyticsRepository->countActiveAvailableRooms();
        $reservations   = $this->analyticsRepository->reservationsIntersectingPeriod($date, $date);
        $arrivals       = $this->analyticsRepository->arrivalsForDay($date);
        $departures     = $this->analyticsRepository->departuresForDay($date);

        // Nuits disponibles pour un seul jour = nombre de chambres
        $nightsAvailable = $availableRooms;

        // Nuits vendues et revenu pour le jour
        $nightsSold    = 0;
        $revenueHt     = '0.00';
        $revenueTtc    = '0.00';

        foreach ($reservations as $reservation) {
            $nightsInPeriod = $this->nightsInPeriod($reservation, $date, $date);
            if ($nightsInPeriod <= 0) {
                continue;
            }

            $nightsSold += $nightsInPeriod;

            $totalNights = $reservation->getNights();
            if ($totalNights <= 0) {
                continue;
            }

            // Prorata du revenu TTC
            $prorataTtc = bcdiv(
                bcmul($reservation->getTotalXof(), (string) $nightsInPeriod, 2),
                (string) $totalNights,
                2,
            );

            $revenueTtc = bcadd($revenueTtc, $prorataTtc, 2);

            // HT = TTC / 1.18
            $prorataHt = bcdiv($prorataTtc, self::TAX_DIVISOR, 2);
            $revenueHt = bcadd($revenueHt, $prorataHt, 2);
        }

        // Chambres "occupées sur ce jour" = réservations checked_in couvrant la date
        $occupiedRooms = $this->countOccupiedRooms($reservations, $date);

        $occupancyRate = $this->calcOccupancyRate($nightsSold, $nightsAvailable);
        $adr           = $this->calcAdr($revenueHt, $nightsSold);
        $revpar        = $this->calcRevpar($revenueHt, $nightsAvailable);

        return new DashboardKpis(
            occupancyRate:       $occupancyRate,
            adrHt:               $adr,
            revparHt:            $revpar,
            roomRevenueHt:       $revenueHt,
            roomRevenueTtc:      $revenueTtc,
            roomNightsAvailable: $nightsAvailable,
            roomNightsSold:      $nightsSold,
            arrivalsToday:       count($arrivals),
            departuresToday:     count($departures),
            occupiedRooms:       $occupiedRooms,
            availableRooms:      $availableRooms,
        );
    }

    /**
     * Rapport sur une période [from, to] (dates incluses).
     */
    public function periodReport(\DateTimeImmutable $from, \DateTimeImmutable $to): PeriodReport
    {
        $availableRoomsPerDay = $this->analyticsRepository->countActiveAvailableRooms();
        $reservations         = $this->analyticsRepository->reservationsIntersectingPeriod($from, $to);

        $days = $this->countDays($from, $to);

        // Agrégats période
        $totalNightsAvailable = $availableRoomsPerDay * $days;
        $totalNightsSold      = 0;
        $totalRevenueHt       = '0.00';
        $totalRevenueTtc      = '0.00';

        // Pré-calculer par réservation les données proratisées pour la période
        foreach ($reservations as $reservation) {
            $nightsInPeriod = $this->nightsInPeriod($reservation, $from, $to);
            if ($nightsInPeriod <= 0) {
                continue;
            }

            $totalNightsSold += $nightsInPeriod;

            $totalNights = $reservation->getNights();
            if ($totalNights <= 0) {
                continue;
            }

            $prorataTtc = bcdiv(
                bcmul($reservation->getTotalXof(), (string) $nightsInPeriod, 2),
                (string) $totalNights,
                2,
            );
            $totalRevenueTtc = bcadd($totalRevenueTtc, $prorataTtc, 2);

            $prorataHt = bcdiv($prorataTtc, self::TAX_DIVISOR, 2);
            $totalRevenueHt = bcadd($totalRevenueHt, $prorataHt, 2);
        }

        $occupancyRate = $this->calcOccupancyRate($totalNightsSold, $totalNightsAvailable);
        $adr           = $this->calcAdr($totalRevenueHt, $totalNightsSold);
        $revpar        = $this->calcRevpar($totalRevenueHt, $totalNightsAvailable);

        // Série journalière — ventilation par jour en PHP (pas de requête par jour)
        $dailySeries = $this->buildDailySeries($from, $to, $reservations, $availableRoomsPerDay);

        return new PeriodReport(
            occupancyRate:       $occupancyRate,
            adrHt:               $adr,
            revparHt:            $revpar,
            roomRevenueHt:       $totalRevenueHt,
            roomRevenueTtc:      $totalRevenueTtc,
            roomNightsAvailable: $totalNightsAvailable,
            roomNightsSold:      $totalNightsSold,
            from:                $from->format('Y-m-d'),
            to:                  $to->format('Y-m-d'),
            dailySeries:         $dailySeries,
        );
    }

    // ── Méthodes de calcul internes ──

    /**
     * Nombre de nuits d'une réservation qui tombent dans la période [from, to] (inclusif).
     *
     * La "nuit du X" = la nuit commençant le soir du X.
     * Une résa [checkIn, checkOut] occupe les nuits checkIn, checkIn+1, …, checkOut-1.
     * On intersecte avec [from, to] :
     *   effectiveStart = max(checkIn, from)
     *   effectiveEnd   = min(checkOut, to + 1 jour)
     *   nuits = effectiveEnd - effectiveStart (en jours), clamped à ≥ 0.
     */
    public function nightsInPeriod(Reservation $reservation, \DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $checkIn  = $reservation->getCheckIn()->setTime(0, 0);
        $checkOut = $reservation->getCheckOut()->setTime(0, 0);
        $from     = $from->setTime(0, 0);
        $toExcl   = $to->modify('+1 day')->setTime(0, 0);

        $effectiveStart = $checkIn > $from ? $checkIn : $from;
        $effectiveEnd   = $checkOut < $toExcl ? $checkOut : $toExcl;

        $diff = (int) $effectiveStart->diff($effectiveEnd)->days;

        // Si effectiveStart >= effectiveEnd, diff sera 0 (ou dates inversées)
        return $effectiveStart < $effectiveEnd ? $diff : 0;
    }

    /**
     * Calcule le revenu HT proratisé d'une réservation pour un jour donné.
     */
    private function revenueHtForDay(Reservation $reservation, \DateTimeImmutable $day): string
    {
        $nightsInDay = $this->nightsInPeriod($reservation, $day, $day);
        if ($nightsInDay <= 0) {
            return '0.00';
        }

        $totalNights = $reservation->getNights();
        if ($totalNights <= 0) {
            return '0.00';
        }

        $prorataTtc = bcdiv(
            bcmul($reservation->getTotalXof(), (string) $nightsInDay, 2),
            (string) $totalNights,
            2,
        );

        return bcdiv($prorataTtc, self::TAX_DIVISOR, 2);
    }

    /**
     * Construit la série journalière : un point par jour de la période.
     * Fait la ventilation en PHP à partir des réservations déjà chargées.
     *
     * @param Reservation[] $reservations
     * @return DailyDataPoint[]
     */
    private function buildDailySeries(
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        array $reservations,
        int $availableRoomsPerDay,
    ): array {
        $series = [];
        $days   = $this->countDays($from, $to);

        for ($i = 0; $i < $days; $i++) {
            $day = $from->modify("+{$i} days")->setTime(0, 0);

            $soldNights  = 0;
            $revenueHt   = '0.00';

            foreach ($reservations as $reservation) {
                $n = $this->nightsInPeriod($reservation, $day, $day);
                if ($n <= 0) {
                    continue;
                }

                $soldNights += $n;
                $revenueHt = bcadd($revenueHt, $this->revenueHtForDay($reservation, $day), 2);
            }

            $occupancyRate = $this->calcOccupancyRate($soldNights, $availableRoomsPerDay);

            $series[] = new DailyDataPoint(
                date:          $day->format('Y-m-d'),
                occupancyRate: $occupancyRate,
                roomRevenueHt: $revenueHt,
                soldNights:    $soldNights,
            );
        }

        return $series;
    }

    /**
     * Nombre de chambres occupées aujourd'hui = réservations checked_in dont le séjour
     * couvre le jour donné. On compte les réservations distinctes (pas les nuits).
     *
     * @param Reservation[] $reservations
     */
    private function countOccupiedRooms(array $reservations, \DateTimeImmutable $day): int
    {
        $count = 0;
        foreach ($reservations as $reservation) {
            if ($reservation->getStatus() !== 'checked_in') {
                continue;
            }
            if ($this->nightsInPeriod($reservation, $day, $day) > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function calcOccupancyRate(int $sold, int $available): string
    {
        if ($available <= 0) {
            return '0.00';
        }

        return bcdiv(bcmul((string) $sold, '100', 2), (string) $available, 2);
    }

    private function calcAdr(string $revenueHt, int $nightsSold): string
    {
        if ($nightsSold <= 0) {
            return '0.00';
        }

        return bcdiv($revenueHt, (string) $nightsSold, 2);
    }

    private function calcRevpar(string $revenueHt, int $nightsAvailable): string
    {
        if ($nightsAvailable <= 0) {
            return '0.00';
        }

        return bcdiv($revenueHt, (string) $nightsAvailable, 2);
    }

    private function countDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $from = $from->setTime(0, 0);
        $to   = $to->setTime(0, 0);
        $diff = (int) $from->diff($to)->days;

        return $diff + 1; // inclusif
    }
}
