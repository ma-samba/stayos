<?php

namespace App\Hotel\Room\Infrastructure\Repository;

use App\Hotel\Room\Domain\Entity\RoomType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class RoomTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoomType::class);
    }
}
