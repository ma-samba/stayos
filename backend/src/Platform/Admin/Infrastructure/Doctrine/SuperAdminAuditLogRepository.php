<?php

declare(strict_types=1);

namespace App\Platform\Admin\Infrastructure\Doctrine;

use App\Platform\Admin\Domain\Entity\SuperAdminAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SuperAdminAuditLog>
 */
class SuperAdminAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SuperAdminAuditLog::class);
    }

    /**
     * Liste paginée avec filtres optionnels. Tri DESC sur createdAt
     * (+ id DESC en secondaire, même justification que les autres
     * audit logs : UUID v7 monotone à la ms près).
     *
     * @return array{data: SuperAdminAuditLog[], total: int}
     */
    public function paginate(
        ?string $actor       = null,
        ?string $tenantSlug  = null,
        ?string $action      = null,
        int     $page        = 1,
        int     $perPage     = 20,
    ): array {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($actor !== null && $actor !== '') {
            $qb->andWhere('a.actorEmail = :actor')->setParameter('actor', $actor);
        }
        if ($tenantSlug !== null && $tenantSlug !== '') {
            $qb->andWhere('a.tenantSlug = :tenantSlug')->setParameter('tenantSlug', $tenantSlug);
        }
        if ($action !== null && $action !== '') {
            $qb->andWhere('a.action = :action')->setParameter('action', $action);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->resetDQLPart('orderBy')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        return [
            'data'  => $qb->getQuery()->getResult(),
            'total' => $total,
        ];
    }
}
