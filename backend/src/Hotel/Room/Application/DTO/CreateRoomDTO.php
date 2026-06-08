<?php

namespace App\Hotel\Room\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateRoomDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 20)]
    public ?string $number = null;

    #[Assert\NotBlank]
    public ?string $typeId = null;

    public ?string $floorId = null;

    public ?string $notes = null;

    public ?bool $isActive = true;
}
