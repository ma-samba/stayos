<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Service;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Cycle de vie d'un abonnement : essai, upgrade, annulation, suspension,
 * vérifications quotidiennes.
 *
 * Toutes les écritures touchent au schema public (Subscription, Tenant,
 * SaasInvoice). Le scheduler doit s'assurer que le search_path est sur
 * public avant d'appeler checkExpirations() — c'est le rôle du handler
 * Messenger qui appelle ce service.
 */
class AbonnementService
{
    public const TRIAL_DURATION_DAYS = 14;
    public const PAYMENT_DUE_DAYS    = 7;

    public const NOTIF_TRIAL_7D    = 'trial_expiring_7d';
    public const NOTIF_TRIAL_1D    = 'trial_expiring_1d';
    public const NOTIF_TRIAL_OVER  = 'trial_expired';
    public const NOTIF_PAY_LINK    = 'payment_link';
    public const NOTIF_PAY_FAILED  = 'payment_failed';
    public const NOTIF_PAY_SUCCESS = 'payment_success';

    public function __construct(
        private readonly EntityManagerInterface  $entityManager,
        private readonly SubscriptionRepository  $subscriptionRepository,
        private readonly SaasInvoiceRepository   $invoiceRepository,
        private readonly TenantRepository        $tenantRepository,
        private readonly SaasInvoiceService      $saasInvoiceService,
        private readonly SubscriptionEmailService $emailService,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Crée un trial qui expire dans N jours. Appelée par l'onboarding.
     */
    public function createTrial(Tenant $tenant, Plan $plan, int $trialDays = self::TRIAL_DURATION_DAYS): Subscription
    {
        $tz  = new \DateTimeZone('Africa/Dakar');
        $now = new \DateTimeImmutable('now', $tz);

        $subscription = new Subscription();
        $subscription->setTenant($tenant);
        $subscription->setPlan($plan);
        $subscription->setStatus('trial');
        $subscription->setTrialEndsAt($now->modify(sprintf('+%d days', $trialDays)));

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        $this->logger->info('subscription.trial_created', [
            'tenant' => $tenant->getSlug(),
            'plan'   => $plan->getName(),
            'ends'   => $subscription->getTrialEndsAt()?->format('Y-m-d'),
        ]);

        return $subscription;
    }

    /**
     * Change le plan. Si trial → bascule en active immédiatement
     * (la période courante démarre maintenant). Si actif → change le
     * plan tout de suite mais GARDE currentPeriodEnd (pas de prorata
     * en V1 — l'upgrade prend effet pour la facturation au prochain
     * renouvellement).
     */
    public function upgrade(Tenant $tenant, Plan $newPlan): Subscription
    {
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        if ($subscription === null) {
            throw new BusinessRuleException(
                "Aucun abonnement actif pour ce tenant : impossible d'upgrader.",
            );
        }

        $tz  = new \DateTimeZone('Africa/Dakar');
        $now = new \DateTimeImmutable('now', $tz);

        $previousPlan = $subscription->getPlan()->getName();
        $subscription->setPlan($newPlan);

        if ($subscription->getStatus() === 'trial') {
            // Trial → ACTIVE : la période commence maintenant
            $subscription->setStatus('active');
            $subscription->setCurrentPeriodStart($now);
            $subscription->setCurrentPeriodEnd($now->modify('+1 month'));
            $subscription->setTrialEndsAt(null);
            // Le tenant peut être en TRIAL : on l'aligne sur ACTIVE
            $tenant->setStatus(TenantStatus::ACTIVE);
        }
        // Si déjà actif : on ne touche pas aux dates — la facturation
        // du nouveau plan se fera au prochain renouvellement.

        // Reset l'historique de relance, le nouveau plan repart à neuf
        $subscription->setLastNotificationType(null);
        $subscription->setLastNotificationSentAt(null);

        $this->entityManager->flush();

        $this->logger->info('subscription.upgraded', [
            'tenant'   => $tenant->getSlug(),
            'from'     => $previousPlan,
            'to'       => $newPlan->getName(),
            'status'   => $subscription->getStatus(),
        ]);

        return $subscription;
    }

    /**
     * Marque la subscription cancelled mais NE suspend PAS le tenant —
     * l'accès reste ouvert jusqu'à la fin de la période payée (ou de
     * l'essai). La suspension effective est déclenchée par
     * checkExpirations() à l'échéance.
     */
    public function cancel(Tenant $tenant): Subscription
    {
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        if ($subscription === null) {
            throw new BusinessRuleException(
                "Aucun abonnement actif à annuler.",
            );
        }

        $tz = new \DateTimeZone('Africa/Dakar');
        $subscription->setStatus('cancelled');
        $subscription->setCancelledAt(new \DateTimeImmutable('now', $tz));

        $this->entityManager->flush();

        $this->logger->info('subscription.cancelled', [
            'tenant'  => $tenant->getSlug(),
            'access_until' => $subscription->getCurrentPeriodEnd()?->format('Y-m-d')
                ?? $subscription->getTrialEndsAt()?->format('Y-m-d'),
        ]);

        return $subscription;
    }

    /**
     * Bloque l'accès. Le TenantMiddleware refusera désormais toute
     * requête sur ce tenant (TenantSuspendedException → 402).
     */
    public function suspend(Tenant $tenant): void
    {
        $tenant->setStatus(TenantStatus::SUSPENDED);
        $this->entityManager->flush();

        $this->logger->warning('tenant.suspended', [
            'tenant' => $tenant->getSlug(),
        ]);
    }

    /**
     * Reverse de suspend(). Utile quand un client règle son retard.
     * Si la subscription était cancelled, elle reste cancelled — il
     * faudra un upgrade pour repartir.
     */
    public function reactivate(Tenant $tenant): void
    {
        $tenant->setStatus(TenantStatus::ACTIVE);
        $this->entityManager->flush();

        $this->logger->info('tenant.reactivated', [
            'tenant' => $tenant->getSlug(),
        ]);
    }

    /**
     * Scanner quotidien — appelé par le scheduler.
     *
     * Pour chaque subscription active OU trial :
     *  - trial expirant dans 7j → email trial-expiring-7d (1 fois)
     *  - trial expirant dans 1j → email trial-expiring-1d (1 fois)
     *  - trial expiré → suspend + email trial-expired
     *  - active dont currentPeriodEnd est passée :
     *      - si aucune facture ouverte → generate + charge + email
     *      - si facture ouverte avec dueAt dépassée → suspend + email failed
     *
     * @return array{processed:int, suspended:int, invoiced:int, errors:int, emailed:int}
     */
    public function checkExpirations(?\DateTimeImmutable $now = null): array
    {
        $tz  = new \DateTimeZone('Africa/Dakar');
        $now ??= new \DateTimeImmutable('now', $tz);

        $stats = ['processed' => 0, 'suspended' => 0, 'invoiced' => 0, 'errors' => 0, 'emailed' => 0];

        $subs = $this->entityManager->getRepository(Subscription::class)->createQueryBuilder('s')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('statuses', ['trial', 'active'])
            ->getQuery()
            ->getResult();

        foreach ($subs as $sub) {
            /** @var Subscription $sub */
            $stats['processed']++;

            try {
                $tenantSuspendedBefore = $sub->getTenant()->getStatus() === TenantStatus::SUSPENDED->value;

                if ($sub->getStatus() === 'trial') {
                    $this->handleTrial($sub, $now, $stats);
                } else {
                    $this->handleActive($sub, $now, $stats);
                }

                if (!$tenantSuspendedBefore
                    && $sub->getTenant()->getStatus() === TenantStatus::SUSPENDED->value) {
                    $stats['suspended']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->logger->error('checkExpirations: tenant error', [
                    'tenant' => $sub->getTenant()->getSlug(),
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('subscription.check_expirations', $stats);

        return $stats;
    }

    private function handleTrial(Subscription $sub, \DateTimeImmutable $now, array &$stats): void
    {
        $endsAt = $sub->getTrialEndsAt();
        if ($endsAt === null) {
            return;
        }

        if ($endsAt < $now) {
            // ─── Trial expiré ───
            $this->suspend($sub->getTenant());
            if ($this->markNotification($sub, self::NOTIF_TRIAL_OVER, $now)
                && $this->emailService->sendTrialExpired($sub)) {
                $stats['emailed']++;
            }
            return;
        }

        $daysLeft = (int) $now->diff($endsAt)->format('%a');

        if ($daysLeft <= 1) {
            if ($this->markNotification($sub, self::NOTIF_TRIAL_1D, $now)
                && $this->emailService->sendTrialExpiring1d($sub)) {
                $stats['emailed']++;
            }
            return;
        }

        if ($daysLeft <= 7) {
            if ($this->markNotification($sub, self::NOTIF_TRIAL_7D, $now)
                && $this->emailService->sendTrialExpiring7d($sub)) {
                $stats['emailed']++;
            }
        }
    }

    private function handleActive(Subscription $sub, \DateTimeImmutable $now, array &$stats): void
    {
        $periodEnd = $sub->getCurrentPeriodEnd();
        if ($periodEnd === null) {
            return;
        }

        $open = $this->invoiceRepository->findOpenForSubscription($sub);

        if ($open !== null) {
            // Facture en attente de règlement : si l'échéance est passée → suspend
            if ($open->getDueAt() !== null && $open->getDueAt() < $now) {
                $this->saasInvoiceService->markFailed($open);
                $this->suspend($sub->getTenant());
                if ($this->markNotification($sub, self::NOTIF_PAY_FAILED, $now)
                    && $this->emailService->sendPaymentFailed($open)) {
                    $stats['emailed']++;
                }
            }
            return;
        }

        if ($periodEnd >= $now) {
            // Pas encore expiré, et pas de facture ouverte → rien à faire
            return;
        }

        // ─── Période expirée, pas de facture ouverte : générer et facturer ───
        $invoice = $this->saasInvoiceService->generateForPeriod(
            $sub,
            $periodEnd,
            $periodEnd->modify('+1 month'),
            self::PAYMENT_DUE_DAYS,
        );
        $stats['invoiced']++;

        $charged = $this->saasInvoiceService->charge($invoice);

        if ($charged) {
            $this->saasInvoiceService->markSent($invoice);
            if ($this->markNotification($sub, self::NOTIF_PAY_LINK, $now)
                && $this->emailService->sendPaymentLink($invoice)) {
                $stats['emailed']++;
            }
        }
    }

    /**
     * Marque qu'une notification d'un type donné vient d'être envoyée.
     * Retourne false si la même notification a déjà été envoyée → le
     * caller ne doit PAS renvoyer l'email.
     */
    private function markNotification(Subscription $sub, string $type, \DateTimeImmutable $now): bool
    {
        if ($sub->getLastNotificationType() === $type) {
            return false;
        }

        $sub->setLastNotificationType($type);
        $sub->setLastNotificationSentAt($now);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Reconduit une subscription active après confirmation de paiement.
     * Appelée par le webhook handler quand l'IPN Paydunya confirme une
     * SaasInvoice payée.
     */
    public function renewAfterPayment(SaasInvoice $invoice): void
    {
        $sub = $invoice->getSubscription();

        if ($invoice->getStatus() !== SaasInvoiceStatus::PAID->value) {
            return;
        }

        $tz = new \DateTimeZone('Africa/Dakar');

        $newStart = $invoice->getPeriodStart();
        $newEnd   = $invoice->getPeriodEnd();

        $sub->setStatus('active');
        $sub->setCurrentPeriodStart($newStart);
        $sub->setCurrentPeriodEnd($newEnd);
        $sub->setLastNotificationType(self::NOTIF_PAY_SUCCESS);
        $sub->setLastNotificationSentAt(new \DateTimeImmutable('now', $tz));

        // Si le tenant avait été suspendu, le réactiver
        if ($sub->getTenant()->getStatus() === TenantStatus::SUSPENDED->value) {
            $sub->getTenant()->setStatus(TenantStatus::ACTIVE);
        }

        $this->entityManager->flush();

        $this->emailService->sendPaymentSuccess($invoice, $newStart, $newEnd);

        $this->logger->info('subscription.renewed_after_payment', [
            'tenant'  => $sub->getTenant()->getSlug(),
            'invoice' => $invoice->getNumber(),
        ]);
    }
}
