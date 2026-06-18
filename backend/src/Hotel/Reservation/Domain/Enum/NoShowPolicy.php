<?php

declare(strict_types=1);

namespace App\Hotel\Reservation\Domain\Enum;

/**
 * Politique de facturation no-show appliquée par le tenant.
 *
 * - NONE         : on passe la résa en NO_SHOW sans rien facturer
 * - FIRST_NIGHT  : 1ère nuit retenue (défaut)
 * - FULL         : intégralité du séjour retenue
 *
 * Surcharge cas par cas possible par le réceptionniste depuis l'UI.
 */
enum NoShowPolicy: string
{
    case NONE        = 'none';
    case FIRST_NIGHT = 'first_night';
    case FULL        = 'full';

    /**
     * @return string[] Liste des valeurs autorisées (pour Assert\Choice).
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
