<?php

namespace App\Platform\Tenant\Infrastructure\Doctrine;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tenant>
 */
class TenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tenant::class);
    }

    /**
     * Charge un tenant par son slug, sans filtre sur le statut.
     * Utilisé par le TenantMiddleware pour distinguer 404 et 402.
     */
    public function findBySlug(string $slug): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne tous les tenants actifs (TRIAL ou ACTIVE).
     *
     * @return Tenant[]
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('statuses', [
                TenantStatus::ACTIVE->value,
                TenantStatus::TRIAL->value,
            ])
            ->orderBy('t.slug', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Charge un tenant actif (TRIAL ou ACTIVE) par son slug.
     * Utilisé par le TenantMiddleware à chaque requête.
     */
    public function findActiveBySlug(string $slug): ?Tenant
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.slug = :slug')
            ->andWhere('t.status IN (:statuses)')
            ->setParameter('slug', $slug)
            ->setParameter('statuses', [
                TenantStatus::ACTIVE->value,
                TenantStatus::TRIAL->value,
            ])
            ->getQuery()
            ->getOneOrNullResult();
    }
}
