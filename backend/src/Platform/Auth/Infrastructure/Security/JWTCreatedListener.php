<?php

namespace App\Platform\Auth\Infrastructure\Security;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Shared\TenantContext;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Psr\Log\LoggerInterface;

/**
 * Enrichit le JWT avec les claims métier : tenant, role, plan, features, hotel.
 * Écouté sur l'événement lexik_jwt_authentication.on_jwt_created.
 */
class JWTCreatedListener
{
    public function __construct(
        private readonly TenantContext          $tenantContext,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly LoggerInterface        $logger,
    ) {}

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        try {
            $user = $event->getUser();

            if (!$user instanceof StaffUser) {
                return;
            }

            $payload = $event->getData();

            if ($this->tenantContext->has()) {
                $tenant       = $this->tenantContext->get();
                $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

                $payload['slug']     = $tenant->getSlug();
                $payload['tenant']   = (string) $tenant->getId();
                $payload['hotel']    = $tenant->getName();
                $payload['role']     = $user->getRole();
                $payload['plan']     = $subscription?->getPlan()->getName() ?? 'STARTER';
                $payload['features'] = $subscription?->getPlan()->getFeatures() ?? [];
            }

            $event->setData($payload);
        } catch (\Throwable $e) {
            $this->logger->error('JWTCreatedListener error', [
                'message' => $e->getMessage(),
                'class'   => $e::class,
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
