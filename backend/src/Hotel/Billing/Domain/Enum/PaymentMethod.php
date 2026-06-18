<?php

namespace App\Hotel\Billing\Domain\Enum;

enum PaymentMethod: string
{
    case CASH          = 'cash';
    case WAVE          = 'wave';
    case ORANGE_MONEY  = 'orange_money';
    case CARD          = 'card';
    case BANK_TRANSFER = 'bank_transfer';
    case MOBILE_MONEY  = 'mobile_money';
    case OTA           = 'ota';

    /**
     * @return string[] Liste des valeurs autorisées (pour Assert\Choice).
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
