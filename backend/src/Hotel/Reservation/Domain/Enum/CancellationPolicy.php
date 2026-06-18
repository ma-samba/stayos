<?php

declare(strict_types=1);

namespace App\Hotel\Reservation\Domain\Enum;

/**
 * Politique d'annulation appliquée par le tenant.
 *
 * - FLEXIBLE (défaut) : annulation gratuite à tout moment
 * - MODERATE          : gratuite > 48h avant check-in,
 *                       1ère nuit retenue 24-48h,
 *                       total retenu < 24h
 * - STRICT            : 1ère nuit toujours retenue, peu importe l'anticipation
 *
 * Surcharge cas par cas possible par le réceptionniste depuis l'UI
 * (geste commercial), tracée dans l'audit log.
 */
enum CancellationPolicy: string
{
    case FLEXIBLE = 'flexible';
    case MODERATE = 'moderate';
    case STRICT   = 'strict';

    /**
     * @return string[] Liste des valeurs autorisées (pour Assert\Choice).
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
