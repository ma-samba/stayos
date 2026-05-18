<?php

namespace App\Shared\Mercure;

use App\Shared\TenantContext;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Centralise toutes les publications Mercure.
 * Les topics sont toujours namespaced par tenant pour l'isolation.
 */
class MercurePublisher
{
    public function __construct(
        private readonly HubInterface  $hub,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Publie un message sur un topic namespaced par tenant.
     * Ex: topic='room.status.changed' → /hotel/{uuid}/room.status.changed
     *
     * Topics standards :
     * - 'room.status.changed'
     * - 'reservation.created'
     * - 'reservation.checkin'
     * - 'reservation.checkout'
     * - 'task.assigned'
     * - 'payment.received'
     */
    public function publish(string $topic, array $payload): void
    {
        if (!$this->tenantContext->has()) {
            return;
        }

        $tenantId = (string) $this->tenantContext->getTenant()->getId();
        $namespacedTopic = sprintf('/hotel/%s/%s', $tenantId, $topic);

        $update = new Update(
            $namespacedTopic,
            json_encode($payload),
        );

        $this->hub->publish($update);
    }
}
