<?php

namespace App\Hotel\Reservation\Domain\Enum;

enum ReservationSource: string
{
    case DIRECT      = 'direct';
    case BOOKING_COM = 'booking_com';
    case AIRBNB      = 'airbnb';
    case EXPEDIA     = 'expedia';
    case WALK_IN     = 'walk_in';
}
