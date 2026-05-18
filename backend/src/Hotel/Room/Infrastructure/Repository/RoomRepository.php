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
     * Retourne toutes les chambres actives avec type et étage chargés (eager loading, évite N+1).
     * Triées par étage ASC, numéro ASC.
     */
    public function findAllWithTypeAndFloor(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('t', 'f')
            ->leftJoin('r.type', 't')
            ->leftJoin('r.floor', 'f')
            ->where('r.isActive = true')
            ->orderBy('f.number', 'ASC')
            ->addOrderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Charge une chambre par UUID avec type et étage.
     */
    public function findByIdWithRelations(string $id): ?Room
    {
        return $this->createQueryBuilder('r')
            ->addSelect('t', 'f')
            ->leftJoin('r.type', 't')
            ->leftJoin('r.floor', 'f')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les chambres disponibles sur une période (pas de réservation CONFIRMED ou CHECKED_IN).
     */
    public function findAvailable(\DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, int $adults): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('t', 'f')
            ->leftJoin('r.type', 't')
            ->leftJoin('r.floor', 'f')
            ->leftJoin(
                'App\Hotel\Reservation\Domain\Entity\Reservation',
                'res',
                'WITH',
                'res.room = r AND res.status IN (:activeStatuses) AND res.checkIn < :checkOut AND res.checkOut > :checkIn'
            )
            ->where('res.id IS NULL')
            ->andWhere('r.isActive = true')
            ->andWhere('t.maxOccupancy >= :adults')
            ->setParameter('activeStatuses', ['confirmed', 'checked_in'])
            ->setParameter('checkIn', $checkIn)
            ->setParameter('checkOut', $checkOut)
            ->setParameter('adults', $adults)
            ->orderBy('f.number', 'ASC')
            ->addOrderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
