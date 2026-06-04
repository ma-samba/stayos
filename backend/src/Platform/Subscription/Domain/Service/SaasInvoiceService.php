<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Service;

use App\Hotel\Billing\Domain\Gateway\PaymentCheckoutRequest;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayRegistry;
use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Facturation SaaS (l'hôtel paye son abonnement à StayOS).
 *
 * ⚠️ Différent du Hotel\Billing\InvoiceService qui gère les factures
 * clients de l'hôtel.
 *
 * ⚠️ V1 : pas de card-on-file (paiement automatique récurrent). Le flux
 * est :
 *   1. generateForPeriod() crée une SaasInvoice DRAFT, snapshot du plan.
 *   2. charge() appelle Paydunya pour créer un checkout, persiste le
 *      gatewayToken + checkoutUrl, passe la facture en PENDING.
 *   3. L'utilisateur reçoit l'email avec le lien (envoyé par le
 *      scheduler, pas ici — séparation des responsabilités) et règle
 *      manuellement avant la dueAt.
 *   4. L'IPN Paydunya appelle markPaid() qui passe en PAID et
 *      enregistre la référence.
 *
 * Le card-on-file est de la dette pour Sprint 14+.
 */
class SaasInvoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SaasInvoiceRepository  $invoiceRepository,
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        #[Target('business')] private readonly LoggerInterface $logger,
        #[Autowire('%default_backend_url%')] private readonly string $backendUrl,
        #[Autowire('%default_frontend_url%')] private readonly string $frontendUrl,
    ) {}

    /**
     * Crée une facture brouillon pour la période donnée. Le montant et
     * le nom du plan sont figés au moment de l'émission — un upgrade
     * ultérieur ne réécrit pas l'historique.
     */
    public function generateForPeriod(
        Subscription       $subscription,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
        int                $dueInDays = 7,
    ): SaasInvoice {
        $tz  = new \DateTimeZone('Africa/Dakar');
        $now = new \DateTimeImmutable('now', $tz);

        $invoice = new SaasInvoice();
        $invoice->setTenant($subscription->getTenant());
        $invoice->setSubscription($subscription);
        $invoice->setPlanName($subscription->getPlan()->getName());
        $invoice->setAmountXof($subscription->getPlan()->getPriceXof());
        $invoice->setPeriodStart($periodStart);
        $invoice->setPeriodEnd($periodEnd);
        $invoice->setDueAt($now->modify(sprintf('+%d days', $dueInDays)));
        $invoice->setNumber($this->invoiceRepository->generateNextNumber($now));
        $invoice->setStatus(SaasInvoiceStatus::DRAFT);

        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        $this->logger->info('saas_invoice.generated', [
            'invoice_number' => $invoice->getNumber(),
            'tenant'         => $subscription->getTenant()->getSlug(),
            'plan'           => $invoice->getPlanName(),
            'amount'         => $invoice->getAmountXof(),
        ]);

        return $invoice;
    }

    /**
     * Crée un checkout Paydunya et passe la facture en PENDING.
     *
     * V1 : Paydunya uniquement. Les autres passerelles seront ajoutées
     * dans un sprint ultérieur.
     *
     * Retourne false si la création du checkout échoue côté gateway —
     * la facture reste en DRAFT pour permettre un retry.
     */
    public function charge(SaasInvoice $invoice): bool
    {
        if ($invoice->getStatus() === SaasInvoiceStatus::PAID->value) {
            return true;
        }

        $tenant  = $invoice->getTenant();
        $gateway = $this->gatewayRegistry->get('paydunya');

        // Secret aléatoire dédié à cette facture — repris du pattern
        // Hotel\Billing\PaymentCheckoutService. L'IPN doit présenter exactement
        // ce secret pour être accepté.
        if ($invoice->getCallbackSecret() === null) {
            $invoice->setCallbackSecret(bin2hex(random_bytes(16)));
        }

        $callbackUrl = sprintf(
            '%s/api/payments/paydunya/ipn?tenant=%s&secret=%s&saas=1',
            rtrim($this->backendUrl, '/'),
            rawurlencode($tenant->getSlug()),
            rawurlencode($invoice->getCallbackSecret()),
        );
        // Les pages de retour vivent sur le sous-domaine tenant (ex:
        // villa-collines.localhost:5173) — sinon le frontend ne peut
        // pas réhydrater le contexte tenant à l'atterrissage.
        $returnUrl = $this->buildTenantUrl($this->frontendUrl, $tenant->getSlug(), '/subscription/payment-return');
        $cancelUrl = $this->buildTenantUrl($this->frontendUrl, $tenant->getSlug(), '/subscription/payment-cancel');

        $request = new PaymentCheckoutRequest(
            invoiceId:     (string) $invoice->getId(),
            tenantSlug:    $tenant->getSlug(),
            amountXof:     (int) round((float) $invoice->getAmountXof()),
            description:   sprintf('Abonnement StayOS — %s', $invoice->getPlanName()),
            customerName: $tenant->getName(),
            customerEmail: null,
            customerPhone: null,
            callbackUrl:   $callbackUrl,
            returnUrl:     $returnUrl,
            cancelUrl:     $cancelUrl,
        );

        $result = $gateway->createCheckout($request);

        if (!$result->ok || $result->checkoutUrl === null) {
            $this->logger->error('saas_invoice.checkout_failed', [
                'invoice' => $invoice->getNumber(),
                'tenant'  => $tenant->getSlug(),
            ]);
            return false;
        }

        $invoice->setPaydunyaToken($result->gatewayToken);
        $invoice->setCheckoutUrl($result->checkoutUrl);
        $invoice->setStatus(SaasInvoiceStatus::PENDING);

        $this->entityManager->flush();

        return true;
    }

    /**
     * Confirme un paiement encaissé. Idempotent : appeler deux fois
     * n'a aucun effet supplémentaire.
     */
    public function markPaid(SaasInvoice $invoice, ?string $paymentReference = null): void
    {
        if ($invoice->getStatus() === SaasInvoiceStatus::PAID->value) {
            return;
        }

        $tz = new \DateTimeZone('Africa/Dakar');

        $invoice->setStatus(SaasInvoiceStatus::PAID);
        $invoice->setPaidAt(new \DateTimeImmutable('now', $tz));
        if ($paymentReference !== null) {
            $invoice->setPaymentReference($paymentReference);
        }

        $this->entityManager->flush();

        $this->logger->info('saas_invoice.paid', [
            'invoice'   => $invoice->getNumber(),
            'tenant'    => $invoice->getTenant()->getSlug(),
            'reference' => $paymentReference,
        ]);
    }

    public function markFailed(SaasInvoice $invoice): void
    {
        if (!$invoice->isOpen()) {
            return;
        }

        $invoice->setStatus(SaasInvoiceStatus::FAILED);
        $this->entityManager->flush();

        $this->logger->info('saas_invoice.failed', [
            'invoice' => $invoice->getNumber(),
            'tenant'  => $invoice->getTenant()->getSlug(),
        ]);
    }

    public function markSent(SaasInvoice $invoice): void
    {
        $invoice->setSentAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));
        $this->entityManager->flush();
    }

    /**
     * Préfixe l'hôte de $baseUrl avec le slug tenant pour produire
     * une URL frontend résoluble par le TenantMiddleware au retour
     * de Paydunya.
     *
     *   http://localhost:5173  + villa-collines  → http://villa-collines.localhost:5173/...
     *   https://stayos.sn      + villa-collines  → https://villa-collines.stayos.sn/...
     */
    private function buildTenantUrl(string $baseUrl, string $tenantSlug, string $path): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? 'localhost';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return sprintf('%s://%s.%s%s%s', $scheme, $tenantSlug, $host, $port, $path);
    }
}
