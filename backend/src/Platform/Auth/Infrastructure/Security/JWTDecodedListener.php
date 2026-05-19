<?php

namespace App\Platform\Auth\Infrastructure\Security;

use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: Events::JWT_DECODED)]
class JWTDecodedListener
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantContext    $tenantContext,
        private readonly Connection       $connection,
    ) {}

    public function __invoke(JWTDecodedEvent $event): void
    {
        $payload = $event->getPayload();

        // Le JWT contient le slug du tenant
        $slug = $payload['slug'] ?? null;
        if (!$slug) {
            return;
        }

        // Si le contexte est déjà défini (TenantMiddleware a tourné), skip
        if ($this->tenantContext->has()) {
            return;
        }

        // Charger le tenant et positionner le search_path
        // pour que loadUserByIdentifier() trouve staff_users
        $tenant = $this->tenantRepository->findBySlug($slug);
        if (!$tenant) {
            $event->markAsInvalid();
            return;
        }

        $schemaName = $tenant->getSchemaName();

        if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            $event->markAsInvalid();
            return;
        }

        $this->tenantContext->set($tenant);
        $this->connection->executeStatement(
            \sprintf('SET search_path TO %s, public', $schemaName)
        );
    }
}
