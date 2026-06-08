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
     *
     * @return AuditLog[]
     */
    public function findByEntity(string $entityType, string $entityId, ?int $limit = null): array
    {
        // ⚠️ Doctrine tronque `created_at` à la seconde (TIMESTAMP(0)) ;
        // deux audit logs émis dans la même seconde auraient le même
        // tri. On ajoute `id DESC` comme second critère : nos UUID v7
        // sont monotones (préfixe timestamp ms), donc l'ordre
        // lexicographique reflète l'ordre d'insertion à la ms près.
        $qb = $this->createQueryBuilder('a')
            ->where('a.entityType = :type AND a.entityId = :id')
            ->setParameter('type', $entityType)
            ->setParameter('id', $entityId)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Actions FAITES PAR un staff donné (toutes entités confondues).
     * Filtre par email : c'est le seul champ stable (un staff peut
     * être désactivé/supprimé, mais l'email reste denormalized
     * dans l'audit log).
     *
     * Même justification du tri secondaire `id DESC` que dans
     * `findByEntity` (TIMESTAMP(0) + UUID v7 monotone).
     *
     * @return AuditLog[]
     */
    public function findByStaffUser(string $email, ?int $limit = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.staffUserEmail = :email')
            ->setParameter('email', $email)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
