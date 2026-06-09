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
     * Chambres marquées OCCUPIED mais sans réservation CHECKED_IN active.
     * Incohérence rare mais critique signalée par la checklist night audit.
     *
     * @return Room[]
     */
    public function findOccupiedWithoutActiveReservation(): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin(
                'App\Hotel\Reservation\Domain\Entity\Reservation',
                'res',
                'WITH',
                'res.room = r AND res.status = :checkedIn'
            )
            ->where('r.status = :occupied')
            ->andWhere('r.isActive = true')
            ->andWhere('res.id IS NULL')
            ->setParameter('checkedIn', 'checked_in')
            ->setParameter('occupied', 'occupied')
            ->orderBy('r.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Snapshot brut de l'état des chambres à l'instant T (toutes les
     * chambres actives avec leur numéro et statut courant).
     * Utilisé pour figer l'image dans le night audit.
     *
     * @return array<int, array{id: string, number: string, status: string}>
     */
    public function snapshotAllStatuses(): array
    {
        $rows = $this->createQueryBuilder('r')
            ->select('r.id AS id, r.number AS number, r.status AS status')
            ->where('r.isActive = true')
            ->orderBy('r.number', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row) => [
            'id'     => (string) $row['id'],
            'number' => (string) $row['number'],
            'status' => (string) $row['status'],
        ], $rows);
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
