<?php

namespace App\Hotel\Analytics\Domain\DTO;

final readonly class DashboardKpis
{
    public function __construct(
        public string $occupancyRate,
        public string $adrHt,
        public string $revparHt,
        public string $roomRevenueHt,
        public string $roomRevenueTtc,
        public int    $roomNightsAvailable,
        public int    $roomNightsSold,
        public int    $arrivalsToday,
        public int    $departuresToday,
        public int    $occupiedRooms,
        public int    $availableRooms,
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
            'arrivalsToday'       => $this->arrivalsToday,
            'departuresToday'     => $this->departuresToday,
            'occupiedRooms'       => $this->occupiedRooms,
            'availableRooms'      => $this->availableRooms,
        ];
    }
}
