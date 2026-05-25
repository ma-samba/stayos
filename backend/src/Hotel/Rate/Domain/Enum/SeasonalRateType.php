<?php

namespace App\Hotel\Rate\Domain\Enum;

enum SeasonalRateType: string
{
    case MULTIPLIER = 'multiplier';
    case ABSOLUTE   = 'absolute';
}
