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

    /**
     * Recherche fulltext sur nom, prénom, email, numéro de document.
     */
    public function search(string $query, int $limit = 10): array
    {
        $q = '%' . $query . '%';

        return $this->createQueryBuilder('g')
            ->where('g.firstName LIKE :q OR g.lastName LIKE :q OR g.email LIKE :q OR g.documentNumber LIKE :q')
            ->setParameter('q', $q)
            ->setMaxResults($limit)
            ->orderBy('g.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
