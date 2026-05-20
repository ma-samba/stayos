<?php

namespace App\Hotel\Guest\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class CreateGuestDTO
{
    #[Assert\NotBlank(message: 'Le prénom est obligatoire')]
    #[Assert\Length(max: 100)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Le nom est obligatoire')]
    #[Assert\Length(max: 100)]
    public string $lastName = '';

    #[Assert\Email(message: 'Email invalide')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Length(max: 30)]
    public ?string $phone = null;

    #[Assert\Length(max: 2)]
    public ?string $nationality = null;

    #[Assert\Length(max: 60)]
    public ?string $documentNumber = null;
}
