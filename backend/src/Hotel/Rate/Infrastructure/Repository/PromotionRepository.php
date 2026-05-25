<?php

namespace App\Hotel\Rate\Infrastructure\Repository;

use App\Hotel\Rate\Domain\Entity\Promotion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

class PromotionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Promotion::class);
    }

    /**
     * Trouve une promotion active par son code pour cet hôtel.
     * La validité fine (dates, usages) est vérifiée par l'entité, pas ici.
     */
    public function findOneActiveByCode(Uuid $hotelId, string $code): ?Promotion
    {
        return $this->createQueryBuilder('p')
            ->where('p.hotel = :hotelId')
            ->andWhere('p.code = :code')
            ->andWhere('p.isActive = true')
            ->setParameter('hotelId', $hotelId, 'uuid')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
