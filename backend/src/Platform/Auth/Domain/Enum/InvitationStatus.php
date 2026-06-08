<?php

declare(strict_types=1);

namespace App\Platform\Auth\Domain\Enum;

enum InvitationStatus: string
{
    case PENDING  = 'pending';
    case ACCEPTED = 'accepted';
    case EXPIRED  = 'expired';
    case REVOKED  = 'revoked';
}
