<?php

namespace App\Hotel\Guest\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateGuestDTO
{
    #[Assert\Length(max: 100)]
    public ?string $firstName = null;

    #[Assert\Length(max: 100)]
    public ?string $lastName = null;

    #[Assert\Email(message: 'Email invalide')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 2)]
    public ?string $nationality = null;

    #[Assert\Length(max: 30)]
    public ?string $documentType = null;

    #[Assert\Length(max: 60)]
    public ?string $documentNumber = null;

    #[Assert\Url]
    #[Assert\Length(max: 500)]
    public ?string $documentUrl = null;

    #[Assert\Length(max: 255)]
    public ?string $address = null;

    #[Assert\Length(max: 100)]
    public ?string $city = null;

    #[Assert\Length(max: 2)]
    public ?string $country = null;
}
