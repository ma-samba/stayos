<?php

namespace App\Platform\Tenant\Infrastructure\Middleware;

use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\TenantNotFoundException;
use App\Shared\Exception\TenantSuspendedException;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Résout le tenant depuis le subdomain et configure le search_path PostgreSQL.
 *
 * Flux : Host: savana.localhost
 *   → slug = "savana"
 *   → Tenant chargé depuis public.tenants
 *   → SET search_path TO hotel_{uuid}, public
 *
 * Priority 20 : s'exécute avant le firewall Symfony (priority 8).
 */
class TenantMiddleware implements EventSubscriberInterface
{
    /**
     * Chemins qui ne déclenchent pas la résolution de tenant.
     */
    private const EXCLUDED_PREFIXES = [
        '/api/health',
        '/api/onboarding/register',
    ];

    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantContext    $tenantContext,
        private readonly Connection       $connection,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();

        // Bypass pour les routes publiques sans contexte tenant
        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        // Extraire le slug depuis le subdomain : "savana.localhost" → "savana"
        $host  = $request->getHost();
        $parts = explode('.', $host);

        // Pas de subdomain (ex: localhost seul) → pas de résolution
        if (\count($parts) < 2) {
            return;
        }

        $slug   = $parts[0];
        $tenant = $this->tenantRepository->findBySlug($slug);

        if (null === $tenant) {
            throw new TenantNotFoundException($slug);
        }

        if (!TenantStatus::from($tenant->getStatus())->isAccessible()) {
            throw new TenantSuspendedException($slug);
        }

        // Stocker dans le contexte (injecté dans tous les services qui en ont besoin)
        $this->tenantContext->set($tenant);

        // Pointer PostgreSQL sur le bon schema
        $schemaName = $tenant->getSchemaName();

        // Validation préventive : le nom de schema ne doit contenir que [hotel_uuid]
        if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            throw new \RuntimeException(\sprintf('Nom de schema invalide : %s', $schemaName));
        }

        $this->connection->executeStatement(
            \sprintf('SET search_path TO %s, public', $schemaName)
        );
    }
}
