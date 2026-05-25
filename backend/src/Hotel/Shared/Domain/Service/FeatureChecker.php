<?php

namespace App\Hotel\Shared\Domain\Service;

use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Shared\Exception\FeatureNotAvailableException;
use App\Shared\TenantContext;

class FeatureChecker
{
    public function __construct(
        private readonly TenantContext          $tenantContext,
        private readonly SubscriptionRepository $subscriptionRepository,
    ) {}

    public function isEnabled(string $feature): bool
    {
        if (!$this->tenantContext->has()) {
            return false;
        }

        $subscription = $this->subscriptionRepository->findActiveByTenant(
            $this->tenantContext->get(),
        );

        if ($subscription === null) {
            return false;
        }

        return in_array($feature, $subscription->getPlan()->getFeatures(), true);
    }

    public function assertEnabled(string $feature): void
    {
        if (!$this->isEnabled($feature)) {
            throw new FeatureNotAvailableException($feature);
        }
    }
}
