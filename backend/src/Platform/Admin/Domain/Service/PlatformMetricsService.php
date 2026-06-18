<?php

declare(strict_types=1);

namespace App\Platform\Admin\Domain\Service;

use App\Platform\Admin\Application\DTO\PlatformMetrics;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcul des métriques agrégées de la plateforme (toutes les
 * subscriptions/tenants confondus). Réservé au SuperAdmin.
 *
 * Toutes les requêtes ciblent le schema public (subscriptions,
 * tenants, plans) — aucune notion de TenantContext.
 */
final class PlatformMetricsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function compute(?\DateTimeImmutable $now = null): PlatformMetrics
    {
        $tz   = new \DateTimeZone('Africa/Dakar');
        $now  = $now ?? new \DateTimeImmutable('now', $tz);
        $past = $now->modify('-30 days');

        // ── Comptages tenants par statut ─────────────────────────
        $statusCounts = $this->entityManager
            ->createQueryBuilder()
            ->select('t.status AS status', 'COUNT(t.id) AS cnt')
            ->from(Tenant::class, 't')
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $byStatus = [
            TenantStatus::ACTIVE->value    => 0,
            TenantStatus::TRIAL->value     => 0,
            TenantStatus::SUSPENDED->value => 0,
            TenantStatus::CHURNED->value   => 0,
        ];
        foreach ($statusCounts as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        // ── MRR : abonnements 'active' uniquement (pas trial) ────
        // Pour billing_cycle = annual, on divise par 12 (préparation
        // V2 même si non encore en place côté Subscription).
        $rows = $this->entityManager
            ->createQueryBuilder()
            ->select('s.billingCycle AS cycle', 'p.priceXof AS price')
            ->from(Subscription::class, 's')
            ->join('s.plan', 'p')
            ->where('s.status = :active')
            ->setParameter('active', 'active')
            ->getQuery()
            ->getArrayResult();

        $mrr = '0';
        foreach ($rows as $row) {
            $price = (string) $row['price'];
            $monthly = $row['cycle'] === 'annual'
                ? bcdiv($price, '12', 2)
                : $price;
            $mrr = bcadd($mrr, $monthly, 2);
        }

        // ── Nouveaux tenants sur 30 jours ────────────────────────
        $newTenants = (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(t.id)')
            ->from(Tenant::class, 't')
            ->where('t.createdAt >= :past')
            ->setParameter('past', $past)
            ->getQuery()
            ->getSingleScalarResult();

        // ── Churn 30 jours : subscriptions passées en cancelled
        //    ou suspended dans la fenêtre (utilise cancelledAt si
        //    présent, sinon le createdAt de la subscription comme
        //    fallback raisonnable — pas d'updated_at sur la
        //    Subscription en l'état).
        $churn = (int) $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Subscription::class, 's')
            ->where('s.status IN (:closed)')
            ->andWhere('COALESCE(s.cancelledAt, s.createdAt) >= :past')
            ->setParameter('closed', ['cancelled', 'suspended'])
            ->setParameter('past', $past)
            ->getQuery()
            ->getSingleScalarResult();

        // ── Distribution par plan (trial + active uniquement) ────
        $planRows = $this->entityManager
            ->createQueryBuilder()
            ->select('p.name AS name', 'COUNT(s.id) AS cnt')
            ->from(Subscription::class, 's')
            ->join('s.plan', 'p')
            ->where('s.status IN (:openStatuses)')
            ->setParameter('openStatuses', ['active', 'trial'])
            ->groupBy('p.name')
            ->getQuery()
            ->getArrayResult();

        $planDistribution = ['STARTER' => 0, 'PRO' => 0, 'ENTERPRISE' => 0];
        foreach ($planRows as $row) {
            $planDistribution[$row['name']] = (int) $row['cnt'];
        }

        return new PlatformMetrics(
            mrr:                   $mrr,
            activeTenantsCount:    $byStatus[TenantStatus::ACTIVE->value],
            trialTenantsCount:     $byStatus[TenantStatus::TRIAL->value],
            suspendedTenantsCount: $byStatus[TenantStatus::SUSPENDED->value],
            churnedTenantsCount:   $byStatus[TenantStatus::CHURNED->value],
            newTenantsLast30Days:  $newTenants,
            churnLast30Days:       $churn,
            planDistribution:      $planDistribution,
        );
    }
}
