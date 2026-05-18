<?php

namespace App\Hotel\Housekeeping\Infrastructure\Repository;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class CleaningTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CleaningTask::class);
    }

    /**
     * Retourne les tâches planifiées pour aujourd'hui.
     */
    public function findForToday(): array
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $start = new \DateTimeImmutable('today', $tz);
        $end   = new \DateTimeImmutable('tomorrow', $tz);

        return $this->createQueryBuilder('t')
            ->where('t.scheduledAt >= :start AND t.scheduledAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('t.status', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
