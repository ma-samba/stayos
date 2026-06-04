<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Enum;

enum SaasInvoiceStatus: string
{
    case DRAFT     = 'draft';
    case PENDING   = 'pending';
    case PAID      = 'paid';
    case FAILED    = 'failed';
    case CANCELLED = 'cancelled';

    public function isOpen(): bool
    {
        return match ($this) {
            self::DRAFT, self::PENDING => true,
            default                    => false,
        };
    }
}
