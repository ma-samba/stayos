<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Service;

use App\Shared\TenantContext;

/**
 * Calcule la "business date" courante d'un tenant en tenant compte de
 * son cutoff hour configuré (par défaut 5h locale du tenant).
 *
 * Exemple cutoff=5h, timezone=Africa/Dakar :
 *   - Il est 03:00 le 10/06 → business date = 09/06
 *   - Il est 07:00 le 10/06 → business date = 10/06
 *
 * La business date retournée est toujours normalisée à 00:00:00 dans
 * la timezone du tenant.
 */
class BusinessDateService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function getCurrentBusinessDate(): \DateTimeImmutable
    {
        $tenant = $this->tenantContext->get();
        $tz     = new \DateTimeZone($tenant->getTimezone());
        $now    = new \DateTimeImmutable('now', $tz);

        return $this->resolve($now, $tenant->getBusinessDayCutoffHour(), $tz);
    }

    /**
     * Pour un instant donné, retourne la business date correspondante
     * dans la timezone du tenant.
     */
    public function toBusinessDate(\DateTimeImmutable $instant): \DateTimeImmutable
    {
        $tenant = $this->tenantContext->get();
        $tz     = new \DateTimeZone($tenant->getTimezone());
        $local  = $instant->setTimezone($tz);

        return $this->resolve($local, $tenant->getBusinessDayCutoffHour(), $tz);
    }

    private function resolve(
        \DateTimeImmutable $localInstant,
        int $cutoffHour,
        \DateTimeZone $tz,
    ): \DateTimeImmutable {
        $businessDate = (int) $localInstant->format('H') < $cutoffHour
            ? $localInstant->modify('-1 day')
            : $localInstant;

        // Reconstruire en date pure dans la TZ tenant (pas d'heure héritée).
        return new \DateTimeImmutable($businessDate->format('Y-m-d') . ' 00:00:00', $tz);
    }
}
