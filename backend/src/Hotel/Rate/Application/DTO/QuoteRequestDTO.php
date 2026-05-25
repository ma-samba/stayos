<?php

namespace App\Hotel\Rate\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class QuoteRequestDTO
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $roomId = '';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $checkIn = '';

    #[Assert\NotBlank]
    #[Assert\Date]
    public string $checkOut = '';

    #[Assert\Uuid]
    public ?string $ratePlanId = null;

    public ?string $promoCode = null;
}
