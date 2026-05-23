<?php

namespace App\Hotel\Billing\Domain\Enum;

enum PaymentStatus: string
{
    case INIT      = 'init';
    case PENDING   = 'pending';
    case PAID      = 'paid';
    case FAILED    = 'failed';
    case CANCELLED = 'cancelled';
}
