<?php

namespace App\Hotel\Property\Infrastructure\Repository;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class HotelProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HotelProfile::class);
    }
}
