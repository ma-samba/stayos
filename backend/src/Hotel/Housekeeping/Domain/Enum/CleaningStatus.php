<?php

namespace App\Hotel\Housekeeping\Domain\Enum;

enum CleaningStatus: string
{
    case PENDING     = 'pending';
    case IN_PROGRESS = 'in_progress';
    case DONE        = 'done';
    case INSPECTED   = 'inspected';
    case SKIPPED     = 'skipped';
}
