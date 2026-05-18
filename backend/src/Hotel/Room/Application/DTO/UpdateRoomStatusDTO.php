<?php

namespace App\Hotel\Room\Application\DTO;

use App\Hotel\Room\Domain\Enum\RoomStatus;
use Symfony\Component\Validator\Constraints as Assert;

class UpdateRoomStatusDTO
{
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [RoomStatus::class, 'values'])]
    public string $status;

    public ?string $notes = null;
}
