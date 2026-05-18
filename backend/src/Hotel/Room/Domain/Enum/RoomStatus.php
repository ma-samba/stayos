<?php

namespace App\Hotel\Room\Domain\Enum;

enum RoomStatus: string
{
    case AVAILABLE    = 'available';
    case OCCUPIED     = 'occupied';
    case CLEANING     = 'cleaning';
    case MAINTENANCE  = 'maintenance';
    case OUT_OF_ORDER = 'out_of_order';

    /** @return string[] */
    public static function values(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }
}
