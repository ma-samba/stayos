<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Gateway;

final readonly class PaymentConfirmation
{
    /**
     * @param string $status 'completed'|'pending'|'cancelled'|'failed'
     */
    public function __construct(
        public bool    $ok,
        public string  $status = 'failed',
        public ?int    $amountXof = null,
        public array   $raw = [],
    ) {}
}
