<?php

namespace App\Platform\Tenant\Domain\Enum;

enum TenantStatus: string
{
    case TRIAL     = 'trial';
    case ACTIVE    = 'active';
    case SUSPENDED = 'suspended';
    case CHURNED   = 'churned';

    public function isAccessible(): bool
    {
        return match($this) {
            self::TRIAL, self::ACTIVE => true,
            self::SUSPENDED, self::CHURNED => false,
        };
    }
}
