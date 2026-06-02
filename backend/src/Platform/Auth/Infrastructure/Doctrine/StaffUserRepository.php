<?php

namespace App\Platform\Auth\Infrastructure\Doctrine;

use App\Platform\Auth\Domain\Entity\StaffUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StaffUser>
 */
class StaffUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StaffUser::class);
    }

    public function findByEmail(string $email): ?StaffUser
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Liste les staff par rôle. Accepte soit la forme courte ("HOUSEKEEPER")
     * soit la forme préfixée Symfony ("ROLE_HOUSEKEEPER"), pour pouvoir être
     * appelée depuis l'API où le client envoie naturellement "ROLE_*".
     *
     * @return StaffUser[]
     */
    public function findByRole(string $role): array
    {
        $normalized = str_starts_with($role, 'ROLE_') ? substr($role, 5) : $role;

        return $this->createQueryBuilder('s')
            ->andWhere('s.role = :role')
            ->andWhere('s.active = true')
            ->setParameter('role', $normalized)
            ->orderBy('s.firstName', 'ASC')
            ->addOrderBy('s.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
