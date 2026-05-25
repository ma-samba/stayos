<?php

namespace App\Hotel\Analytics\Domain\DTO;

final readonly class PeriodReport
{
    /**
     * @param DailyDataPoint[] $dailySeries
     */
    public function __construct(
        public string $occupancyRate,
        public string $adrHt,
        public string $revparHt,
        public string $roomRevenueHt,
        public string $roomRevenueTtc,
        public int    $roomNightsAvailable,
        public int    $roomNightsSold,
        public string $from,
        public string $to,
        public array  $dailySeries,
    ) {}

    public function toArray(): array
    {
        return [
            'occupancyRate'       => $this->occupancyRate,
            'adrHt'               => $this->adrHt,
            'revparHt'            => $this->revparHt,
            'roomRevenueHt'       => $this->roomRevenueHt,
            'roomRevenueTtc'      => $this->roomRevenueTtc,
            'roomNightsAvailable' => $this->roomNightsAvailable,
            'roomNightsSold'      => $this->roomNightsSold,
            'from'                => $this->from,
            'to'                  => $this->to,
            'dailySeries'         => array_map(fn(DailyDataPoint $p) => $p->toArray(), $this->dailySeries),
        ];
    }
}
