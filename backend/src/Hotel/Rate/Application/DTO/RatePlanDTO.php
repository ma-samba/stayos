<?php

namespace App\Hotel\Rate\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class RatePlanDTO
{
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Montant invalide.')]
    public string $baseRateXof = '';

    #[Assert\Uuid]
    public ?string $roomTypeId = null;

    #[Assert\PositiveOrZero]
    public ?int $minNights = null;

    #[Assert\Date]
    public ?string $validFrom = null;

    #[Assert\Date]
    public ?string $validTo = null;

    public bool $isActive = true;
}
