<?php

namespace App\Hotel\Property\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateFloorDTO
{
    #[Assert\Type('int')]
    #[Assert\Range(min: -10, max: 200)]
    public ?int $number = null;

    #[Assert\Length(max: 100)]
    public ?string $name = null;
}
