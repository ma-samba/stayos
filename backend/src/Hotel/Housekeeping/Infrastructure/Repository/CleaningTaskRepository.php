<?php

namespace App\Hotel\Housekeeping\Infrastructure\Repository;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
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

    /**
     * Tâches pour le kanban, avec jointures pour éviter les N+1.
     *
     * @param string|null              $assignedToId UUID du staff (filtre HOUSEKEEPER)
     * @param \DateTimeImmutable|null  $date         Jour à afficher (défaut : aujourd'hui)
     *
     * @return CleaningTask[]
     */
    public function findForBoard(?string $assignedToId = null, ?\DateTimeImmutable $date = null): array
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        if ($date === null) {
            $date = new \DateTimeImmutable('today', $tz);
        }

        $start = $date->setTime(0, 0, 0);
        $end   = $start->modify('+1 day');

        $qb = $this->createQueryBuilder('t')
            ->addSelect('r', 'rt')
            ->join('t.room', 'r')
            ->leftJoin('r.type', 'rt')
            ->where('t.scheduledAt >= :start AND t.scheduledAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('t.scheduledAt', 'ASC');

        if ($assignedToId !== null) {
            $qb->andWhere('t.assignedTo = :staffId')
                ->setParameter('staffId', $assignedToId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Vérifie si une tâche non terminée existe déjà pour cette chambre ce jour.
     * Sert à l'idempotence de la génération quotidienne.
     */
    public function hasActiveTaskForRoomOnDate(string $roomId, \DateTimeImmutable $date): bool
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $start->modify('+1 day');

        $terminalStatuses = [
            CleaningStatus::DONE->value,
            CleaningStatus::INSPECTED->value,
            CleaningStatus::SKIPPED->value,
        ];

        $count = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.room = :roomId')
            ->andWhere('t.scheduledAt >= :start AND t.scheduledAt < :end')
            ->andWhere('t.status NOT IN (:terminalStatuses)')
            ->setParameter('roomId', $roomId)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('terminalStatuses', $terminalStatuses)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
