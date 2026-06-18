<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayRegistry;
use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Domain\Service\SaasInvoiceService;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
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
        private readonly SaasInvoiceRepository   $saasInvoiceRepository,
        private readonly SaasInvoiceService      $saasInvoiceService,
        private readonly AbonnementService       $abonnementService,
        #[Target('business')] private readonly LoggerInterface $logger,
        // Sprint 14-B.1.2.2 — Vérification hash SHA-512 MasterKey
        // Paydunya. Valeurs par défaut : compatibles avec les
        // instanciations directes en test unitaire.
        private readonly string                  $paydunyaMasterKey = '',
        private readonly bool                    $hashVerificationEnabled = false,
    ) {}

    /**
     * Point d'entree principal pour l'IPN.
     *
     * @param array  $payload        Corps brut du webhook (json decode)
     * @param string $providedSecret Secret fourni dans le query param
     * @param string $tenantSlug     Slug du tenant fourni dans le query param
     * @param bool   $isSaas         true si l'IPN concerne une SaasInvoice
     *                               (abonnement plateforme), false si paiement
     *                               client hôtel (cas historique Sprint 7).
     */
    public function handle(
        array  $payload,
        string $providedSecret,
        string $tenantSlug,
        bool   $isSaas = false,
    ): void {
        // ── Étape 0 : vérification de la source Paydunya (Sprint 14-B.1.2.2) ──
        // Le hash est le SHA-512 STATIQUE de la MasterKey. Il prouve que
        // l'expéditeur connaît la MasterKey — pas qu'il a signé ce message
        // précis (limite du protocole Paydunya, doc HTTP/JSON). Combiné au
        // callback secret par paiement et à la reconfirmation gateway, on
        // a une défense en profondeur. Désactivable via
        // PAYDUNYA_HASH_VERIFICATION_ENABLED en dev/test.
        if ($this->hashVerificationEnabled
            && !$this->isPaydunyaHashValid($payload)
        ) {
            $this->logger->warning('IPN: invalid Paydunya source hash', [
                'tenant_slug' => $tenantSlug,
                'is_saas'     => $isSaas,
            ]);
            return;
        }

        $this->logger->info('IPN received', [
            'tenant_slug'  => $tenantSlug,
            'is_saas'      => $isSaas,
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

            if ($isSaas) {
                // Flux SaaS : SaasInvoice est dans public.saas_invoices ;
                // le search_path tenant est inoffensif (on accède à public.*
                // par nom qualifié) — on garde le même pattern que le flux
                // métier pour la cohérence du finally.
                $this->processSaasPayment($payload, $providedSecret, $tenantSlug);
            } else {
                $this->processPayment($payload, $providedSecret, $tenantSlug);
            }
        } catch (\Throwable $e) {
            $this->logger->error('IPN processing error', [
                'tenant_slug' => $tenantSlug,
                'error'       => $e->getMessage(),
                'class'       => $e::class,
            ]);
        } finally {
            // TOUJOURS restaurer le search_path
            $this->connection->executeStatement('SET search_path TO public');
        }
    }

    /**
     * Vérifie que le payload IPN vient de Paydunya en comparant le hash
     * fourni avec sha512(MasterKey).
     *
     * Source : doc Paydunya HTTP/JSON, section "Configuration de l'IPN" —
     * « Le hash renvoyé par PayDunya est le hash de votre MasterKey. […]
     * L'algorithme utilisé est du SHA-512. »
     *
     * Limites connues (documentées pour les futurs lecteurs) :
     * - Hash CONSTANT pour notre compte → vulnérable au replay théorique
     *   d'un IPN légitime intercepté.
     * - Hash ne couvre PAS le body → un attaquant qui connaîtrait la
     *   MasterKey pourrait altérer n'importe quel champ.
     *
     * Mitigations en aval :
     * - Idempotence via verrou pessimiste (replay sans effet).
     * - verifyPayment via gateway (anti-tampering du montant).
     * - Callback secret custom StayOS par paiement (étape 5).
     */
    private function isPaydunyaHashValid(array $payload): bool
    {
        // Mauvaise config prod (flag ON mais MasterKey vide) : on refuse
        // tous les IPN. Préférable à accepter sans vérifier — l'erreur
        // sera visible dans les logs et corrigeable via Heroku Config Vars.
        if ($this->paydunyaMasterKey === '') {
            $this->logger->error(
                'IPN: hash verification enabled but PAYDUNYA_MASTER_KEY is empty — '
                . 'check Heroku Config Vars',
            );
            return false;
        }

        $providedHash = $payload['hash'] ?? null;
        if (!\is_string($providedHash) || $providedHash === '') {
            return false;
        }

        $expectedHash = \hash('sha512', $this->paydunyaMasterKey);
        return \hash_equals($expectedHash, $providedHash);
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
                'class'      => $e::class,
            ]);
        }
    }

    /**
     * Flux IPN pour les factures SaaS (abonnements StayOS).
     *
     * Structure identique au flux métier (lookup → secret → confirm →
     * amount check → section critique avec verrou) mais sur SaasInvoice
     * dans public.saas_invoices.
     *
     * Décision : sur secret invalide / amount mismatch / confirmation
     * non "completed", on NE marque PAS la SaasInvoice en FAILED ici —
     * on log et on sort. C'est uniquement le scheduler quotidien à
     * `dueAt` qui décide de la suspension. Rationale : Paydunya peut
     * envoyer plusieurs IPN intermédiaires avec des montants ou des
     * statuts incohérents (re-tentatives, paiements partiels d'OM),
     * il vaut mieux attendre la confirmation finale ou l'échéance.
     */
    private function processSaasPayment(array $payload, string $providedSecret, string $tenantSlug): void
    {
        $gatewayToken = $payload['invoice']['token'] ?? null;

        if ($gatewayToken === null || $gatewayToken === '') {
            $this->logger->warning('IPN saas: missing gateway token in payload', [
                'tenant_slug' => $tenantSlug,
            ]);
            return;
        }

        $invoice = $this->saasInvoiceRepository->findByPaydunyaToken($gatewayToken);

        if ($invoice === null) {
            $this->logger->warning('IPN saas: invoice not found for token', [
                'gateway_token' => $gatewayToken,
                'tenant_slug'   => $tenantSlug,
            ]);
            return;
        }

        // Idempotence : si déjà PAID, sortir sans rien faire.
        if ($invoice->getStatus() === SaasInvoiceStatus::PAID->value) {
            $this->logger->info('IPN saas: already paid, skipping', [
                'invoice_number' => $invoice->getNumber(),
            ]);
            return;
        }

        // Secret stocké par facture (cf. SaasInvoiceService::charge).
        $storedSecret = $invoice->getCallbackSecret();

        if ($storedSecret === null || !hash_equals($storedSecret, $providedSecret)) {
            $this->logger->warning('IPN saas: invalid callback secret', [
                'invoice_number' => $invoice->getNumber(),
                'tenant_slug'    => $tenantSlug,
            ]);
            return;
        }

        // Reconfirmation serveur-à-serveur (anti-fraude).
        $gateway      = $this->gatewayRegistry->get('paydunya');
        $confirmation = $gateway->confirmPayment($gatewayToken);

        if (!$confirmation->ok || $confirmation->status !== 'completed') {
            $this->logger->warning('IPN saas: server-side confirmation failed', [
                'invoice_number' => $invoice->getNumber(),
                'status'         => $confirmation->status ?? 'unknown',
            ]);
            return;
        }

        // Vérification du montant (anti-fraude).
        $expectedAmount = (int) round((float) $invoice->getAmountXof());

        if ($confirmation->amountXof !== null && $confirmation->amountXof !== $expectedAmount) {
            $this->logger->error('IPN saas: amount mismatch', [
                'invoice_number'  => $invoice->getNumber(),
                'expected_amount' => $expectedAmount,
                'received_amount' => $confirmation->amountXof,
            ]);
            return;
        }

        // Section critique : verrou pessimiste pour bloquer le double IPN.
        $invoiceId = $invoice->getId();
        $this->entityManager->wrapInTransaction(function () use ($invoiceId, $confirmation, $tenantSlug): void {
            /** @var SaasInvoice $locked */
            $locked = $this->entityManager->find(
                SaasInvoice::class,
                $invoiceId,
                LockMode::PESSIMISTIC_WRITE,
            );

            // Idempotence DANS le verrou.
            if ($locked->getStatus() === SaasInvoiceStatus::PAID->value) {
                $this->logger->info('IPN saas: already paid (locked check), skipping', [
                    'invoice_number' => $locked->getNumber(),
                ]);
                return;
            }

            $paymentRef = $confirmation->raw['receipt_identifier']
                ?? $confirmation->raw['invoice']['receipt_identifier']
                ?? $confirmation->raw['receipt_url']
                ?? null;

            $this->saasInvoiceService->markPaid($locked, is_string($paymentRef) ? $paymentRef : null);
            $this->abonnementService->renewAfterPayment($locked);

            $this->logger->info('IPN saas: invoice paid + subscription renewed', [
                'invoice_number' => $locked->getNumber(),
                'tenant_slug'    => $tenantSlug,
            ]);
        });
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
