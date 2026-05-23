<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Gateway;

final readonly class PaymentCheckoutRequest
{
    /**
     * @param string[] $channels ex: ['wave','orange-money','card']
     */
    public function __construct(
        public string $invoiceId,
        public string $tenantSlug,
        public int    $amountXof,
        public string $description,
        public string $customerName,
        public ?string $customerEmail,
        public ?string $customerPhone,
        public string $callbackUrl,
        public string $returnUrl,
        public string $cancelUrl,
        public array  $channels = [],
    ) {}
}
