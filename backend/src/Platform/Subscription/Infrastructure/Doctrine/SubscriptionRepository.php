<?php

namespace App\Platform\Subscription\Infrastructure\Doctrine;

use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Retourne l'abonnement actif (trial ou active) d'un tenant.
     */
    public function findActiveByTenant(Tenant $tenant): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.status IN (:statuses)')
            ->setParameter('tenant', $tenant)
            ->setParameter('statuses', ['trial', 'active'])
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne la dernière subscription d'un tenant, sans filtre statut.
     */
    public function findByTenant(Tenant $tenant): ?Subscription
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
