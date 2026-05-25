<?php

namespace App\Hotel\Rate\Infrastructure\Repository;

use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class SeasonalRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonalRate::class);
    }

    /**
     * Retourne les SeasonalRate actifs couvrant $date pour cet hôtel/roomType,
     * triés par priority DESC (le premier = le plus prioritaire).
     *
     * @return SeasonalRate[]
     */
    public function findActiveForDate(Uuid $hotelId, ?Uuid $roomTypeId, \DateTimeImmutable $date): array
    {
        $qb = $this->createQueryBuilder('s')
            ->where('s.hotel = :hotelId')
            ->andWhere('s.isActive = true')
            ->andWhere('s.startDate <= :date')
            ->andWhere('s.endDate >= :date')
            ->setParameter('hotelId', $hotelId, 'uuid')
            ->setParameter('date', $date->format('Y-m-d'));

        if ($roomTypeId !== null) {
            $qb->andWhere('s.roomType IS NULL OR s.roomType = :roomTypeId')
               ->setParameter('roomTypeId', $roomTypeId, 'uuid');
        } else {
            $qb->andWhere('s.roomType IS NULL');
        }

        $qb->orderBy('s.priority', 'DESC');

        return $qb->getQuery()->getResult();
    }
}
