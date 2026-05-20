<?php

namespace App\Hotel\Guest\Infrastructure\Repository;

use App\Hotel\Guest\Domain\Entity\Guest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class GuestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guest::class);
    }

    public function searchByQuery(string $query): array
    {
        $like = '%' . strtolower($query) . '%';

        return $this->createQueryBuilder('g')
            ->where('LOWER(g.firstName) LIKE :q')
            ->orWhere('LOWER(g.lastName) LIKE :q')
            ->orWhere('LOWER(g.email) LIKE :q')
            ->orWhere('g.phone LIKE :q')
            ->setParameter('q', $like)
            ->orderBy('g.lastName', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}
