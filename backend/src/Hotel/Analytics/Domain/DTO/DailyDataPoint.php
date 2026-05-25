<?php

namespace App\Hotel\Analytics\Domain\DTO;

final readonly class DailyDataPoint
{
    public function __construct(
        public string $date,
        public string $occupancyRate,
        public string $roomRevenueHt,
        public int    $soldNights,
    ) {}

    public function toArray(): array
    {
        return [
            'date'           => $this->date,
            'occupancyRate'  => $this->occupancyRate,
            'roomRevenueHt'  => $this->roomRevenueHt,
            'soldNights'     => $this->soldNights,
        ];
    }
}
