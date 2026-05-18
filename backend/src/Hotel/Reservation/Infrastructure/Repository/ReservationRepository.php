<?php

namespace App\Hotel\Reservation\Infrastructure\Repository;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Filtre les réservations par statut, dates et/ou client.
     */
    public function findWithFilters(?string $status = null, ?string $from = null, ?string $to = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.guest', 'g')
            ->leftJoin('r.room', 'room')
            ->addSelect('g', 'room');

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        if ($from) {
            $qb->andWhere('r.checkIn >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }

        if ($to) {
            $qb->andWhere('r.checkOut <= :to')->setParameter('to', new \DateTimeImmutable($to));
        }

        return $qb->orderBy('r.checkIn', 'DESC')->getQuery()->getResult();
    }

    /**
     * Vérifie s'il existe un conflit pour une chambre sur une période.
     * Retourne true si la chambre est libre.
     */
    public function isRoomAvailable(string $roomId, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, ?string $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.room = :roomId')
            ->andWhere('r.status IN (:activeStatuses)')
            ->andWhere('r.checkIn < :checkOut')
            ->andWhere('r.checkOut > :checkIn')
            ->setParameter('roomId', $roomId)
            ->setParameter('activeStatuses', ['confirmed', 'checked_in'])
            ->setParameter('checkIn', $checkIn)
            ->setParameter('checkOut', $checkOut);

        if ($excludeId) {
            $qb->andWhere('r.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() === 0;
    }
}
