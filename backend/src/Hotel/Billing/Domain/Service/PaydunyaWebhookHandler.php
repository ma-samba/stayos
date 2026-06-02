<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayRegistry;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Email\EmailService;
use App\Shared\Mercure\MercurePublisher;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Traite les IPN Paydunya en contexte multi-tenant.
 *
 * Le webhook arrive sur une route publique SANS subdomain,
 * le tenant est identifie via le query param `tenant` de la callback URL.
 * Le search_path est positionne manuellement puis TOUJOURS restaure dans un finally.
 */
class PaydunyaWebhookHandler
{
    public function __construct(
        private readonly PaymentGatewayRegistry  $gatewayRegistry,
        private readonly TenantRepository        $tenantRepository,
        private readonly EntityManagerInterface   $entityManager,
        private readonly Connection               $connection,
        private readonly InvoiceService           $invoiceService,
        private readonly EmailService             $emailService,
        private readonly MercurePublisher         $mercurePublisher,
        private readonly TenantContext            $tenantContext,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Point d'entree principal pour l'IPN.
     *
     * @param array  $payload        Corps brut du webhook (json decode)
     * @param string $providedSecret Secret fourni dans le query param
     * @param string $tenantSlug     Slug du tenant fourni dans le query param
     */
    public function handle(array $payload, string $providedSecret, string $tenantSlug): void
    {
        $this->logger->info('IPN received', [
            'tenant_slug' => $tenantSlug,
            'payload_keys' => array_keys($payload),
        ]);

        // 1. Resoudre le tenant
        $tenant = $this->tenantRepository->findActiveBySlug($tenantSlug);

        if ($tenant === null) {
            $this->logger->warning('IPN: tenant not found or inactive', [
                'tenant_slug' => $tenantSlug,
            ]);
            return;
        }

        // 2. Positionner le search_path sur le schema du tenant
        $schemaName = $tenant->getSchemaName();

        if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            $this->logger->error('IPN: invalid schema name', [
                'schema' => $schemaName,
                'tenant_slug' => $tenantSlug,
            ]);
            return;
        }

        try {
            $this->connection->executeStatement(
                \sprintf('SET search_path TO %s, public', $schemaName)
            );

            // Set TenantContext so MercurePublisher can namespace topics
            $this->tenantContext->set($tenant);

            $this->processPayment($payload, $providedSecret, $tenantSlug);
        } catch (\Throwable $e) {
            $this->logger->error('IPN processing error', [
                'tenant_slug' => $tenantSlug,
                'error'       => $e->getMessage(),
            ]);
        } finally {
            // TOUJOURS restaurer le search_path
            $this->connection->executeStatement('SET search_path TO public');
        }
    }

    private function processPayment(array $payload, string $providedSecret, string $tenantSlug): void
    {
        // 3. Extraire le token gateway depuis le payload
        //    Le controller extrait deja la cle "data" du form-data,
        //    donc ici on accede directement a invoice.token.
        $gatewayToken = $payload['invoice']['token'] ?? null;

        if ($gatewayToken === null || $gatewayToken === '') {
            $this->logger->warning('IPN: missing gateway token in payload', [
                'tenant_slug' => $tenantSlug,
            ]);
            return;
        }

        // 4. Trouver le Payment par gateway token (lecture initiale, sans verrou)
        $payment = $this->entityManager->getRepository(Payment::class)
            ->findOneBy(['gatewayToken' => $gatewayToken]);

        if ($payment === null) {
            $this->logger->warning('IPN: payment not found for token', [
                'gateway_token' => $gatewayToken,
                'tenant_slug'   => $tenantSlug,
            ]);
            return;
        }

        // 5. Verifier le callback secret (timing-safe, avant le verrou)
        $storedSecret = $payment->getCallbackSecret();

        if ($storedSecret === null || !hash_equals($storedSecret, $providedSecret)) {
            $this->logger->warning('IPN: invalid callback secret', [
                'payment_id'  => (string) $payment->getId(),
                'tenant_slug' => $tenantSlug,
            ]);
            return;
        }

        // 6. Verifier le gateway name (pas de fallback silencieux)
        $gatewayName = $payment->getGatewayName();

        if ($gatewayName === null) {
            $this->logger->error('IPN: payment has no gateway name', [
                'payment_id' => (string) $payment->getId(),
            ]);
            return;
        }

        // 7. Reconfirmation serveur-a-serveur via le gateway (avant le verrou)
        $gateway = $this->gatewayRegistry->get($gatewayName);
        $confirmation = $gateway->confirmPayment($gatewayToken);

        if (!$confirmation->ok || $confirmation->status !== 'completed') {
            $this->logger->warning('IPN: server-side confirmation failed', [
                'payment_id' => (string) $payment->getId(),
                'status'     => $confirmation->status ?? 'unknown',
            ]);

            $payment->setStatusEnum(PaymentStatus::FAILED);
            $payment->setRawPayload($payload);
            $this->entityManager->flush();
            return;
        }

        // 8. Verification du montant
        $expectedAmount = (int) round((float) $payment->getAmountXof());

        if ($confirmation->amountXof !== null && $confirmation->amountXof !== $expectedAmount) {
            $this->logger->error('IPN: amount mismatch', [
                'payment_id'      => (string) $payment->getId(),
                'expected_amount' => $expectedAmount,
                'received_amount' => $confirmation->amountXof,
            ]);

            $payment->setStatusEnum(PaymentStatus::FAILED);
            $payment->setRawPayload($payload);
            $this->entityManager->flush();
            return;
        }

        // 9. Section critique : verrou pessimiste pour empecher le double-traitement
        //    (deux IPN quasi-simultanes sur le meme token)
        $paymentId = $payment->getId();
        $this->entityManager->wrapInTransaction(function () use ($paymentId, $payload, $confirmation, $tenantSlug): void {
            /** @var Payment $locked */
            $locked = $this->entityManager->find(Payment::class, $paymentId, LockMode::PESSIMISTIC_WRITE);

            // Idempotence DANS le verrou : si deja PAID, sortir
            if ($locked->getStatusEnum() === PaymentStatus::PAID) {
                $this->logger->info('IPN: already paid (locked check), skipping', [
                    'payment_id' => (string) $locked->getId(),
                ]);
                return;
            }

            // Passage PAID
            $locked->setStatusEnum(PaymentStatus::PAID);
            $locked->setPaidAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));
            $locked->setRawPayload($payload);

            // Mettre a jour la methode de paiement reelle depuis le payload de confirmation
            $resolvedMethod = $this->resolvePaymentMethod($confirmation->raw);
            if ($resolvedMethod !== null) {
                $locked->setMethodEnum($resolvedMethod);
            }

            // 10. Recalculer le statut de la facture
            $invoice = $locked->getInvoice();
            if ($invoice->isFullyPaid()) {
                $invoice->setStatusEnum(InvoiceStatus::PAID);
            } else {
                $invoice->setStatusEnum(InvoiceStatus::PARTIAL);
            }

            // flush implicite au commit de wrapInTransaction
            $this->entityManager->flush();

            $this->logger->info('IPN: payment confirmed', [
                'payment_id'     => (string) $locked->getId(),
                'invoice_id'     => (string) $invoice->getId(),
                'invoice_number' => $invoice->getNumber(),
                'amount'         => $locked->getAmountXof(),
                'method'         => $locked->getMethod(),
                'invoice_status' => $invoice->getStatus(),
                'tenant_slug'    => $tenantSlug,
            ]);
        });

        // 11. Notification temps réel APRES le commit
        $invoice = $payment->getInvoice();
        $this->mercurePublisher->publish('payment.received', [
            'invoiceId'     => (string) $invoice->getId(),
            'invoiceNumber' => $invoice->getNumber(),
            'amountXof'     => $payment->getAmountXof(),
            'method'        => $payment->getMethod(),
            'guestName'     => $invoice->getReservation()?->getGuest()?->getFullName(),
            'paidAt'        => $payment->getPaidAt()?->format('c'),
        ]);

        // 12. Email non-bloquant APRES le commit (pas dans la transaction)
        try {
            $pdfContent = $this->invoiceService->generatePdf($invoice);
            $this->emailService->sendInvoice($invoice, $pdfContent);
        } catch (\Throwable $e) {
            $this->logger->warning('IPN: invoice email failed (non-blocking)', [
                'invoice_id' => (string) $invoice->getId(),
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrait le moyen de paiement reel depuis le payload de confirmation Paydunya.
     *
     * Paydunya renvoie le canal dans receipt_url ou custom_data selon le flux.
     * Le champ exact peut varier ; on cherche dans les emplacements connus.
     */
    private function resolvePaymentMethod(array $raw): ?PaymentMethod
    {
        // Paydunya renvoie le canal dans 'payment_method' ou dans le receipt
        $channel = $raw['payment_method']
            ?? $raw['invoice']['payment_method']
            ?? $raw['channel']
            ?? null;

        if ($channel === null || !is_string($channel)) {
            return null;
        }

        $normalized = strtolower(trim($channel));

        return match (true) {
            str_contains($normalized, 'wave')   => PaymentMethod::WAVE,
            str_contains($normalized, 'orange')  => PaymentMethod::ORANGE_MONEY,
            str_contains($normalized, 'card'),
            str_contains($normalized, 'visa'),
            str_contains($normalized, 'master')  => PaymentMethod::CARD,
            default                              => null,
        };
    }
}
