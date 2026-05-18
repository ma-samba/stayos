<?php

namespace App\Hotel\Billing\Domain\Enum;

enum InvoiceStatus: string
{
    case DRAFT     = 'draft';
    case ISSUED    = 'issued';
    case PAID      = 'paid';
    case PARTIAL   = 'partial';
    case CANCELLED = 'cancelled';
}
