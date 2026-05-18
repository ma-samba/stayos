<?php

namespace App\Hotel\Reservation\Domain\Enum;

enum ReservationStatus: string
{
    case CONFIRMED   = 'confirmed';
    case PENDING     = 'pending';
    case CHECKED_IN  = 'checked_in';
    case CHECKED_OUT = 'checked_out';
    case CANCELLED   = 'cancelled';
    case NO_SHOW     = 'no_show';
}
