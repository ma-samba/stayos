<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Gateway;

final readonly class PaymentCheckoutResult
{
    public function __construct(
        public bool    $ok,
        public ?string $checkoutUrl = null,
        public ?string $gatewayToken = null,
        public array   $raw = [],
    ) {}
}
