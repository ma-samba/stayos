<?php

namespace App\Hotel\Room\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class BulkCreateRoomsDTO
{
    #[Assert\NotBlank]
    public ?string $floorId = null;

    #[Assert\NotBlank]
    public ?string $typeId = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 9999)]
    public ?int $startNumber = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 50, notInRangeMessage: 'Création en lot limitée à 50 chambres par appel.')]
    public ?int $count = null;

    #[Assert\Length(max: 10)]
    public ?string $prefix = null;
}
