<?php

namespace App\Hotel\Rate\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class SeasonalRateDTO
{
    #[Assert\NotBlank]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['multiplier', 'absolute'], message: 'Type invalide.')]
    public string $type = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Valeur invalide.')]
    public string $value = '';

    #[Assert\Uuid]
    public ?string $roomTypeId = null;

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $startDate = '';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $endDate = '';

    #[Assert\PositiveOrZero]
    public int $priority = 0;

    public bool $isActive = true;
}
