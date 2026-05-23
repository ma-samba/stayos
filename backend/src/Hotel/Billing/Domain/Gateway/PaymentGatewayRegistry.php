<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Gateway;

use App\Shared\Exception\BusinessRuleException;

class PaymentGatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /**
     * @param iterable<PaymentGatewayInterface> $gateways
     */
    public function __construct(iterable $gateways)
    {
        foreach ($gateways as $gateway) {
            $this->gateways[$gateway->getName()] = $gateway;
        }
    }

    public function get(string $name): PaymentGatewayInterface
    {
        if (!isset($this->gateways[$name])) {
            throw new BusinessRuleException(
                sprintf('Passerelle de paiement "%s" inconnue.', $name)
            );
        }

        return $this->gateways[$name];
    }
}
