<?php

declare(strict_types=1);

namespace App\Platform\Auth\Infrastructure\Doctrine;

use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Platform\Auth\Domain\Enum\InvitationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffInvitation>
 */
class StaffInvitationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffInvitation::class);
    }

    public function findByTokenHash(string $tokenHash): ?StaffInvitation
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function findPendingByEmail(string $email): ?StaffInvitation
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.email = :email')
            ->andWhere('i.status = :pending')
            ->setParameter('email', $email)
            ->setParameter('pending', InvitationStatus::PENDING->value)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPending(): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.status = :pending')
            ->setParameter('pending', InvitationStatus::PENDING->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param string[] $statuses Liste de InvitationStatus::value à inclure.
     *                          Si vide, renvoie tout.
     * @return StaffInvitation[]
     */
    public function findByStatuses(array $statuses = []): array
    {
        $qb = $this->createQueryBuilder('i')
            ->orderBy('i.createdAt', 'DESC');

        if ($statuses !== []) {
            $qb->andWhere('i.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

        return $qb->getQuery()->getResult();
    }
}
