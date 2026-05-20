<?php

namespace App\Hotel\Room\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateRoomTypeDTO
{
    #[Assert\Length(max: 100)]
    public ?string $name = null;

    #[Assert\PositiveOrZero(message: 'Le prix doit être positif')]
    public ?string $baseRateXof = null;

    #[Assert\Range(min: 1, max: 20)]
    public ?int $maxOccupancy = null;

    public ?string $description = null;
}
