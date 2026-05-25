<?php

namespace App\Hotel\Reservation\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateReservationDTO
{
    #[Assert\Uuid]
    public ?string $roomId = null;

    #[Assert\Uuid]
    public ?string $guestId = null;

    #[Assert\Date]
    public ?string $checkIn = null;

    #[Assert\Date]
    public ?string $checkOut = null;

    #[Assert\Positive]
    public ?int $adults = null;

    #[Assert\PositiveOrZero]
    public ?int $children = null;

    public ?string $notes = null;
    public ?string $specialRequests = null;
    public ?string $source = null;
    public ?string $depositXof = null;

    #[Assert\Uuid]
    public ?string $ratePlanId = null;

    public ?string $promoCode = null;
}
