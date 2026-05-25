<?php

namespace App\Hotel\Rate\Domain\Enum;

enum PromotionType: string
{
    case PERCENTAGE = 'percentage';
    case FIXED      = 'fixed';
}
