<?php

namespace App\Hotel\Property\Infrastructure\Repository;

use App\Hotel\Property\Domain\Entity\Floor;
use App\Hotel\Room\Domain\Entity\Room;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FloorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Floor::class);
    }

    /**
     * @return Floor[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('f')
            ->orderBy('f.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsByNumber(int $number, ?string $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.number = :number')
            ->setParameter('number', $number);

        if ($excludeId !== null) {
            $qb->andWhere('f.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Compte les chambres rattachées à un étage (utilisé avant DELETE).
     */
    public function countRoomsOnFloor(Floor $floor): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Room::class, 'r')
            ->where('r.floor = :floor')
            ->setParameter('floor', $floor)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return string[] Numéros des chambres rattachées (max 10 pour l'erreur)
     */
    public function getRoomNumbersOnFloor(Floor $floor, int $limit = 10): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r.number')
            ->from(Room::class, 'r')
            ->where('r.floor = :floor')
            ->setParameter('floor', $floor)
            ->orderBy('r.number', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }
}
