<?php

namespace App\Hotel\Room\Infrastructure\Repository;

use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RoomTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomType::class);
    }

    /**
     * Vérifie si un autre type partage le même nom (case-insensitive).
     */
    public function existsByNameCaseInsensitive(string $name, ?string $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('LOWER(t.name) = :name')
            ->setParameter('name', mb_strtolower($name));

        if ($excludeId !== null) {
            $qb->andWhere('t.id != :id')->setParameter('id', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function countRoomsOfType(RoomType $type): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Room::class, 'r')
            ->where('r.type = :type')
            ->setParameter('type', $type)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return string[] Numéros des chambres utilisant ce type (max 10).
     */
    public function getRoomNumbersOfType(RoomType $type, int $limit = 10): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('r.number')
            ->from(Room::class, 'r')
            ->where('r.type = :type')
            ->setParameter('type', $type)
            ->orderBy('r.number', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function getNextSortOrder(): int
    {
        $max = $this->createQueryBuilder('t')
            ->select('MAX(t.sortOrder)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
