<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Billing\Domain\Service\PaydunyaWebhookHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint IPN Paydunya — route publique (pas de JWT).
 *
 * REGLE : toujours repondre 200 pour eviter les retries
 * de la part de Paydunya, meme si le traitement echoue.
 */
class PaydunyaWebhookController extends AbstractController
{
    public function __construct(
        private readonly PaydunyaWebhookHandler $webhookHandler,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/payments/paydunya/ipn', name: 'api_payments_paydunya_ipn', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $secret     = $request->query->get('secret', '');
        $tenantSlug = $request->query->get('tenant', '');
        // `?saas=1` distingue les IPN d'abonnement SaaS (SaasInvoice
        // dans public.saas_invoices) des paiements clients hôtel.
        $isSaas     = $request->query->getBoolean('saas');

        if ($secret === '' || $tenantSlug === '') {
            $this->logger->warning('Paydunya IPN: missing query params', [
                'ip' => $request->getClientIp(),
            ]);
            return new JsonResponse(['status' => 'ok'], 200);
        }

        // Paydunya envoie du form-data (application/x-www-form-urlencoded)
        // avec la cle "data" contenant le JSON du paiement.
        // Fallback JSON pour compatibilite future.
        $payload = $request->request->all('data');

        if (empty($payload)) {
            $json = json_decode($request->getContent() ?: '[]', true);
            $payload = $json['data'] ?? $json ?? [];
        }

        // Log temporaire pour diagnostiquer le format reel en prod
        $this->logger->info('Paydunya IPN: raw payload received', [
            'content_type' => $request->headers->get('Content-Type'),
            'payload_keys' => is_array($payload) ? array_keys($payload) : 'not_array',
            'tenant_slug'  => $tenantSlug,
        ]);

        if (!is_array($payload) || $payload === []) {
            $this->logger->warning('Paydunya IPN: empty or invalid payload', [
                'ip'          => $request->getClientIp(),
                'tenant_slug' => $tenantSlug,
            ]);
            return new JsonResponse(['status' => 'ok'], 200);
        }

        try {
            $this->webhookHandler->handle($payload, $secret, $tenantSlug, $isSaas);
        } catch (\Throwable $e) {
            // Absorber toute erreur — ne jamais renvoyer d'erreur a Paydunya
            $this->logger->error('Paydunya IPN: unhandled error', [
                'error'       => $e->getMessage(),
                'tenant_slug' => $tenantSlug,
            ]);
        }

        return new JsonResponse(['status' => 'ok'], 200);
    }
}
