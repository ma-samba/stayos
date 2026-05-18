<?php

namespace App\Hotel\Property\Infrastructure\Repository;

use App\Hotel\Property\Domain\Entity\Floor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FloorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Floor::class);
    }
}
