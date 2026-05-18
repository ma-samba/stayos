<?php

namespace App\Hotel\Shared\Infrastructure\Repository;

use App\Hotel\Shared\Domain\Entity\AuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * Historique d'une entité (ex: 'Reservation', id).
     */
    public function findByEntity(string $entityType, string $entityId): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.entityType = :type AND a.entityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
