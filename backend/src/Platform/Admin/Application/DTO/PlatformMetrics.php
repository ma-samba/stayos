<?php

declare(strict_types=1);

namespace App\Platform\Admin\Application\DTO;

/**
 * Snapshot des métriques plateforme à un instant T.
 *
 * Calculées live à chaque requête (pas de cache en V1). À optimiser
 * via Redis si la plateforme grossit (Sprint 14+).
 *
 * @phpstan-type PlanDistribution array{STARTER:int, PRO:int, ENTERPRISE:int}
 */
final readonly class PlatformMetrics
{
    /**
     * @param PlanDistribution $planDistribution
     */
    public function __construct(
        public string $mrr,
        public int    $activeTenantsCount,
        public int    $trialTenantsCount,
        public int    $suspendedTenantsCount,
        public int    $cancelledTenantsCount,
        public int    $newTenantsLast30Days,
        public int    $churnLast30Days,
        public array  $planDistribution,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mrr'                    => $this->mrr,
            'activeTenantsCount'     => $this->activeTenantsCount,
            'trialTenantsCount'      => $this->trialTenantsCount,
            'suspendedTenantsCount'  => $this->suspendedTenantsCount,
            'cancelledTenantsCount'  => $this->cancelledTenantsCount,
            'newTenantsLast30Days'   => $this->newTenantsLast30Days,
            'churnLast30Days'        => $this->churnLast30Days,
            'planDistribution'       => $this->planDistribution,
        ];
    }
}
