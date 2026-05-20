<?php

namespace App\Hotel\Room\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateRoomDTO
{
    #[Assert\Length(max: 20)]
    public ?string $number = null;

    public ?string $typeId = null;

    public ?string $floorId = null;

    public ?string $notes = null;

    public ?bool $isActive = null;
}
