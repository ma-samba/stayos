<?php

namespace App\Hotel\Reservation\Infrastructure\Repository;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
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

    /**
     * Charge une réservation par UUID avec guest, room et roomType (eager loading).
     */
    public function findByIdWithRelations(string $id): ?Reservation
    {
        return $this->createQueryBuilder('r')
            ->addSelect('g', 'room', 'rt')
            ->leftJoin('r.guest', 'g')
            ->leftJoin('r.room', 'room')
            ->leftJoin('room.type', 'rt')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne la réservation en cours (CHECKED_IN) pour une chambre.
     */
    public function findCheckedInByRoom(string $roomId): ?Reservation
    {
        return $this->createQueryBuilder('r')
            ->addSelect('g', 'room')
            ->leftJoin('r.guest', 'g')
            ->leftJoin('r.room', 'room')
            ->where('r.room = :roomId')
            ->andWhere('r.status = :status')
            ->setParameter('roomId', $roomId)
            ->setParameter('status', ReservationStatus::CHECKED_IN->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Retourne les réservations pour le planning Gantt.
     * Filtre par période, eager load room + roomType.
     */
    public function findForGantt(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('room', 'rt')
            ->leftJoin('r.room', 'room')
            ->leftJoin('room.type', 'rt')
            ->where('r.checkIn < :to')
            ->andWhere('r.checkOut > :from')
            ->andWhere('r.status IN (:visibleStatuses)')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('visibleStatuses', [
                ReservationStatus::CONFIRMED->value,
                ReservationStatus::CHECKED_IN->value,
                ReservationStatus::CHECKED_OUT->value,
            ])
            ->orderBy('room.number', 'ASC')
            ->addOrderBy('r.checkIn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Génère un numéro de confirmation unique : RES-YYYY-NNNNN
     */
    public function generateConfirmationNumber(): string
    {
        $year = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')))->format('Y');
        $prefix = 'RES-' . $year . '-';

        $lastNumber = $this->createQueryBuilder('r')
            ->select('r.confirmationNumber')
            ->where('r.confirmationNumber LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('r.confirmationNumber', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastNumber === null) {
            return $prefix . '00001';
        }

        $sequence = (int) substr($lastNumber['confirmationNumber'], strlen($prefix));

        return $prefix . str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
    }
}
