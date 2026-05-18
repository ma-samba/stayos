<?php

namespace App\Hotel\Rate\Infrastructure\Repository;

use App\Hotel\Rate\Domain\Entity\RatePlan;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RatePlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RatePlan::class);
    }
}
