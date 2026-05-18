<?php

namespace App\Hotel\Housekeeping\Domain\Enum;

enum CleaningType: string
{
    case DEPARTURE   = 'departure';
    case STAY_OVER   = 'stay_over';
    case INSPECTION  = 'inspection';
    case MAINTENANCE = 'maintenance';
}
