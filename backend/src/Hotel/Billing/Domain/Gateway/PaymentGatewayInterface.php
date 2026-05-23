<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Gateway;

interface PaymentGatewayInterface
{
    public function getName(): string;

    /**
     * Crée un checkout et retourne l'URL de paiement + le token gateway.
     */
    public function createCheckout(PaymentCheckoutRequest $request): PaymentCheckoutResult;

    /**
     * Reconfirme un paiement côté serveur (source de vérité).
     */
    public function confirmPayment(string $gatewayToken): PaymentConfirmation;
}
