<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Infrastructure\Gateway;

use App\Hotel\Billing\Domain\Gateway\PaymentCheckoutRequest;
use App\Hotel\Billing\Domain\Gateway\PaymentCheckoutResult;
use App\Hotel\Billing\Domain\Gateway\PaymentConfirmation;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PaydunyaGateway implements PaymentGatewayInterface
{
    private const BASE_URL_TEST = 'https://app.paydunya.com/sandbox-api/v1';
    private const BASE_URL_LIVE = 'https://app.paydunya.com/api/v1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Target('external')] private readonly LoggerInterface $logger,
        #[Autowire('%env(PAYDUNYA_MASTER_KEY)%')] private readonly string $masterKey,
        #[Autowire('%env(PAYDUNYA_PRIVATE_KEY)%')] private readonly string $privateKey,
        #[Autowire('%env(PAYDUNYA_TOKEN)%')] private readonly string $token,
        #[Autowire('%env(PAYDUNYA_MODE)%')] private readonly string $mode,
    ) {}

    public function getName(): string
    {
        return 'paydunya';
    }

    public function createCheckout(PaymentCheckoutRequest $request): PaymentCheckoutResult
    {
        $payload = [
            'invoice' => [
                'total_amount' => $request->amountXof,
                'description'  => $request->description,
            ],
            'store' => [
                'name'    => 'StayOS',
                'tagline' => 'Gestion hôtelière',
            ],
            'custom_data' => [
                'invoice_id'  => $request->invoiceId,
                'tenant_slug' => $request->tenantSlug,
            ],
            'actions' => [
                'callback_url' => $request->callbackUrl,
                'return_url'   => $request->returnUrl,
                'cancel_url'   => $request->cancelUrl,
            ],
        ];

        if ($request->channels !== []) {
            $payload['channels'] = $request->channels;
        }

        $this->logger->info('Paydunya checkout create', [
            'amount'      => $request->amountXof,
            'invoice_id'  => $request->invoiceId,
            'tenant_slug' => $request->tenantSlug,
        ]);

        try {
            $response = $this->httpClient->request('POST', $this->apiBase() . '/checkout-invoice/create', [
                'headers' => $this->headers(),
                'json'    => $payload,
                'timeout' => 15,
            ]);

            $data = $response->toArray(false);

            if (($data['response_code'] ?? '') !== '00') {
                $this->logger->error('Paydunya checkout failed', [
                    'response_code' => $data['response_code'] ?? null,
                    'response_text' => $data['response_text'] ?? null,
                ]);

                return new PaymentCheckoutResult(ok: false, raw: $data);
            }

            $checkoutUrl  = $data['response_text'] ?? null;
            $gatewayToken = $data['token'] ?? null;

            $this->logger->info('Paydunya checkout created', [
                'token'        => $gatewayToken,
                'checkout_url' => $checkoutUrl,
            ]);

            return new PaymentCheckoutResult(
                ok: true,
                checkoutUrl: $checkoutUrl,
                gatewayToken: $gatewayToken,
                raw: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Paydunya checkout HTTP error', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new PaymentCheckoutResult(ok: false);
        }
    }

    public function confirmPayment(string $gatewayToken): PaymentConfirmation
    {
        $this->logger->info('Paydunya confirm payment', ['token' => $gatewayToken]);

        try {
            $response = $this->httpClient->request('GET', $this->apiBase() . '/checkout-invoice/confirm/' . $gatewayToken, [
                'headers' => $this->headers(),
                'timeout' => 15,
            ]);

            $data = $response->toArray(false);

            if (($data['response_code'] ?? '') !== '00') {
                $this->logger->error('Paydunya confirm failed', [
                    'response_code' => $data['response_code'] ?? null,
                    'token'         => $gatewayToken,
                ]);

                return new PaymentConfirmation(ok: false, raw: $data);
            }

            $status    = $data['status'] ?? 'failed';
            $amountXof = isset($data['invoice']['total_amount'])
                ? (int) $data['invoice']['total_amount']
                : null;

            $this->logger->info('Paydunya confirm result', [
                'token'  => $gatewayToken,
                'status' => $status,
                'amount' => $amountXof,
            ]);

            return new PaymentConfirmation(
                ok: true,
                status: $status,
                amountXof: $amountXof,
                raw: $data,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Paydunya confirm HTTP error', [
                'token' => $gatewayToken,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return new PaymentConfirmation(ok: false);
        }
    }

    private function apiBase(): string
    {
        return $this->mode === 'live' ? self::BASE_URL_LIVE : self::BASE_URL_TEST;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Content-Type'        => 'application/json',
            'PAYDUNYA-MASTER-KEY' => $this->masterKey,
            'PAYDUNYA-PRIVATE-KEY'=> $this->privateKey,
            'PAYDUNYA-TOKEN'      => $this->token,
        ];
    }
}
