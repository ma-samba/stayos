<?php

namespace App\Shared;

use App\Platform\Tenant\Domain\Entity\Tenant;

/**
 * Stocke le tenant courant pour la durée d'une requête HTTP.
 * Service shared (singleton par requête en PHP-FPM).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): Tenant
    {
        if (null === $this->tenant) {
            throw new \RuntimeException(
                'Aucun tenant défini pour cette requête. Le TenantMiddleware a-t-il été appliqué ?'
            );
        }

        return $this->tenant;
    }

    public function has(): bool
    {
        return null !== $this->tenant;
    }
}
