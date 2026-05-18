<?php

namespace App\Hotel\Room\Infrastructure\Repository;

use App\Hotel\Room\Domain\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * Retourne les chambres disponibles sur une période (pas de réservation CONFIRMED ou CHECKED_IN).
     */
    public function findAvailable(\DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, int $adults): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin(
                'App\Hotel\Reservation\Domain\Entity\Reservation',
                'res',
                'WITH',
                'res.room = r AND res.status IN (:activeStatuses) AND res.checkIn < :checkOut AND res.checkOut > :checkIn'
            )
            ->where('res.id IS NULL')
            ->andWhere('r.isActive = true')
            ->andWhere('r.type IN (SELECT rt FROM App\Hotel\Room\Domain\Entity\RoomType rt WHERE rt.maxOccupancy >= :adults)')
            ->setParameter('activeStatuses', ['confirmed', 'checked_in'])
            ->setParameter('checkIn', $checkIn)
            ->setParameter('checkOut', $checkOut)
            ->setParameter('adults', $adults)
            ->getQuery()
            ->getResult();
    }
}
