<?php

namespace App\Platform\Auth\Infrastructure\Doctrine;

use App\Platform\Auth\Domain\Entity\OtpToken;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OtpToken>
 */
class OtpTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OtpToken::class);
    }

    /**
     * Retourne un OTP valide : non expiré, non utilisé, moins de 3 tentatives.
     */
    public function findValidToken(string $email, string $type): ?OtpToken
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.email = :email')
            ->andWhere('o.type = :type')
            ->andWhere('o.usedAt IS NULL')
            ->andWhere('o.expiresAt > :now')
            ->andWhere('o.attempts < 3')
            ->setParameter('email', $email)
            ->setParameter('type', $type)
            ->setParameter('now', new \DateTimeImmutable())
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Invalide tous les OTP actifs pour un email + type (usedAt = now).
     */
    public function invalidateAll(string $email, string $type): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $this->createQueryBuilder('o')
            ->update()
            ->set('o.usedAt', ':now')
            ->andWhere('o.email = :email')
            ->andWhere('o.type = :type')
            ->andWhere('o.usedAt IS NULL')
            ->setParameter('now', $now)
            ->setParameter('email', $email)
            ->setParameter('type', $type)
            ->getQuery()
            ->execute();
    }
}
