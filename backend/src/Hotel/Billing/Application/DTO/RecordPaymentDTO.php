<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class RecordPaymentDTO
{
    #[Assert\NotBlank(message: 'Le moyen de paiement est obligatoire.')]
    public string $method;

    #[Assert\NotBlank(message: 'Le montant est obligatoire.')]
    #[Assert\Positive(message: 'Le montant doit être positif.')]
    public string $amountXof;

    public ?string $reference = null;

    public ?string $notes = null;
}
