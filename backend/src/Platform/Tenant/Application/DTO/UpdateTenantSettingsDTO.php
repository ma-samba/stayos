<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Application\DTO;

use App\Hotel\Reservation\Domain\Enum\CancellationPolicy;
use App\Hotel\Reservation\Domain\Enum\NoShowPolicy;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * DTO pour PATCH /api/tenant/settings.
 *
 * Tous les champs sont OPTIONNELS — l'endpoint accepte un PATCH partiel
 * (1, 2 ou 3 champs). Validation par enum sur les chaînes (NoShowPolicy /
 * CancellationPolicy). businessDayCutoffHour borné 0-23.
 */
final class UpdateTenantSettingsDTO
{
    #[Assert\Choice(
        callback: [NoShowPolicy::class, 'values'],
        message: 'Politique no-show invalide.',
    )]
    public ?string $noShowPolicy = null;

    #[Assert\Choice(
        callback: [CancellationPolicy::class, 'values'],
        message: 'Politique d\'annulation invalide.',
    )]
    public ?string $cancellationPolicy = null;

    /**
     * Typage mixed pour permettre à Assert\Type de rejeter les chaînes (sinon
     * une assignation de string sur ?int crashe avant la validation).
     */
    #[Assert\Type(type: 'integer', message: 'L\'heure de bascule doit être un entier.')]
    #[Assert\Range(
        min: 0,
        max: 23,
        notInRangeMessage: 'L\'heure de bascule doit être comprise entre 0 et 23.',
    )]
    public mixed $businessDayCutoffHour = null;
}
