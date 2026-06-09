<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Infrastructure\Repository;

use App\Hotel\NightAudit\Domain\Entity\DailyClose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DailyClose>
 */
class DailyCloseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DailyClose::class);
    }

    public function findByBusinessDate(\DateTimeImmutable $date): ?DailyClose
    {
        return $this->findOneBy(['businessDate' => $date->setTime(0, 0, 0)]);
    }

    /**
     * Dernière clôture en BDD, rouverte ou non.
     */
    public function findLatest(): ?DailyClose
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.businessDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Dernière clôture EFFECTIVE (non rouverte). C'est elle qui
     * détermine le verrou métier : tant qu'elle n'est pas rouverte,
     * tout ce qui est <= sa business_date est verrouillé.
     */
    public function findLatestEffective(): ?DailyClose
    {
        return $this->createQueryBuilder('c')
            ->where('c.reopenedAt IS NULL')
            ->orderBy('c.businessDate', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return DailyClose[]
     */
    public function paginate(int $page, int $perPage): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return $this->createQueryBuilder('c')
            ->orderBy('c.businessDate', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
