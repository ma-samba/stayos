<?php

namespace App\Hotel\Room\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateRoomTypeDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 100)]
    public ?string $name = null;

    #[Assert\Length(max: 1000)]
    public ?string $description = null;

    #[Assert\NotNull]
    #[Assert\Positive(message: 'Le prix doit être strictement positif')]
    public ?string $baseRateXof = null;

    #[Assert\NotNull]
    #[Assert\Range(min: 1, max: 20)]
    public ?int $maxOccupancy = null;

    /**
     * Ex: [{"type":"king","count":1}, {"type":"single","count":1}]
     */
    public ?array $bedConfiguration = null;

    /**
     * Ex: ["wifi","ac","minibar"]
     */
    public ?array $amenities = null;

    #[Assert\PositiveOrZero]
    public ?int $sortOrder = null;
}
