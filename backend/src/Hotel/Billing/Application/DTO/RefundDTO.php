<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Application\DTO;

use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO de remboursement — Sprint 13quinquies-B.
 *
 * `$amountXof` est attendu STRICTEMENT POSITIF côté API ; le service
 * `InvoiceService::refundPayment` se charge de le négativer avant
 * persistance (matérialisation d'une sortie de caisse).
 */
class RefundDTO
{
    #[Assert\NotBlank(message: 'Le montant est obligatoire.')]
    #[Assert\Type(type: 'numeric', message: 'Le montant doit être un nombre.')]
    #[Assert\GreaterThan(value: 0, message: 'Le montant doit être strictement positif.')]
    public string $amountXof = '';

    #[Assert\NotBlank(message: 'La méthode de remboursement est obligatoire.')]
    #[Assert\Choice(
        callback: [PaymentMethod::class, 'values'],
        message: 'Méthode de remboursement inconnue.'
    )]
    public string $method = '';

    #[Assert\NotBlank(message: 'La raison est obligatoire.')]
    #[Assert\Length(
        min: 5,
        minMessage: 'La raison doit faire au moins {{ limit }} caractères.'
    )]
    public string $reason = '';
}
