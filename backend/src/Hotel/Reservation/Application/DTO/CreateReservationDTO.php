<?php

namespace App\Hotel\Reservation\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateReservationDTO
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $roomId;

    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $guestId;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $checkIn;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $checkOut;

    #[Assert\Positive]
    public int $adults = 1;

    #[Assert\PositiveOrZero]
    public int $children = 0;

    public ?string $notes = null;
    public ?string $specialRequests = null;
    public string  $source = 'direct';
    public ?string $depositXof = null;
}
