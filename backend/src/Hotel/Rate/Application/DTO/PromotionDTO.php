<?php

namespace App\Hotel\Rate\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class PromotionDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    public string $code = '';

    public ?string $description = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['percentage', 'fixed'], message: 'Type invalide.')]
    public string $type = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Valeur invalide.')]
    public string $value = '';

    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Montant invalide.')]
    public ?string $maxDiscountXof = null;

    #[Assert\Positive]
    public int $minNights = 1;

    #[Assert\Regex(pattern: '/^\d+(\.\d{1,2})?$/', message: 'Montant invalide.')]
    public ?string $minAmountXof = null;

    #[Assert\Date]
    public ?string $validFrom = null;

    #[Assert\Date]
    public ?string $validTo = null;

    #[Assert\Positive]
    public ?int $maxUses = null;

    /** @var string[]|null */
    public ?array $applicableRoomTypeIds = null;

    /** @var string[]|null */
    public ?array $applicableRatePlanIds = null;

    public bool $isActive = true;
}
