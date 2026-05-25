<?php

namespace App\Hotel\Analytics\Infrastructure\Repository;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use Doctrine\ORM\EntityManagerInterface;

class AnalyticsRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Réservations non annulées dont le séjour intersecte [from, to].
     * Intersection = checkIn < to+1 AND checkOut > from.
     * (to est inclusif : une résa qui finit le jour to a sa dernière nuit = to-1,
     *  mais une résa dont checkIn = to a une nuit dans la période.)
     *
     * @return Reservation[]
     */
    public function reservationsIntersectingPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // to est inclusif → la borne haute est to+1 jour (checkIn < to+1)
        $toExclusive = $to->modify('+1 day');

        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reservation::class, 'r')
            ->where('r.checkIn < :toExclusive')
            ->andWhere('r.checkOut > :from')
            ->andWhere('r.status NOT IN (:excludedStatuses)')
            ->setParameter('from', $from)
            ->setParameter('toExclusive', $toExclusive)
            ->setParameter('excludedStatuses', [
                ReservationStatus::CANCELLED->value,
                ReservationStatus::NO_SHOW->value,
            ])
            ->getQuery()
            ->getResult();
    }

    /**
     * Nombre de chambres actives et non hors-service au moment du calcul.
     * Exclut MAINTENANCE et OUT_OF_ORDER.
     */
    public function countActiveAvailableRooms(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Room::class, 'r')
            ->where('r.isActive = true')
            ->andWhere('r.status NOT IN (:excludedStatuses)')
            ->setParameter('excludedStatuses', [
                RoomStatus::MAINTENANCE->value,
                RoomStatus::OUT_OF_ORDER->value,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Réservations avec check-in prévu un jour donné et statut confirmed.
     *
     * @return Reservation[]
     */
    public function arrivalsForDay(\DateTimeImmutable $day): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reservation::class, 'r')
            ->leftJoin('r.guest', 'g')
            ->addSelect('g')
            ->where('r.checkIn = :day')
            ->andWhere('r.status = :status')
            ->setParameter('day', $day)
            ->setParameter('status', ReservationStatus::CONFIRMED->value)
            ->getQuery()
            ->getResult();
    }

    /**
     * Réservations avec check-out prévu un jour donné et statut checked_in.
     *
     * @return Reservation[]
     */
    public function departuresForDay(\DateTimeImmutable $day): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')
            ->from(Reservation::class, 'r')
            ->leftJoin('r.guest', 'g')
            ->addSelect('g')
            ->where('r.checkOut = :day')
            ->andWhere('r.status = :status')
            ->setParameter('day', $day)
            ->setParameter('status', ReservationStatus::CHECKED_IN->value)
            ->getQuery()
            ->getResult();
    }
}
