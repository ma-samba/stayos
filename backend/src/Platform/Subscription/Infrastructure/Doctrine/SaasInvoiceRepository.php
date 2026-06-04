<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Infrastructure\Doctrine;

use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaasInvoice>
 */
class SaasInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaasInvoice::class);
    }

    /**
     * @return SaasInvoice[]
     */
    public function findByTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('i.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Facture ouverte (draft ou pending) la plus récente pour une subscription.
     * Utilisée par le scheduler pour éviter de regénérer une facture quand
     * l'utilisateur n'a pas encore payé la précédente.
     */
    public function findOpenForSubscription(Subscription $subscription): ?SaasInvoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.subscription = :sub')
            ->andWhere('i.status IN (:open)')
            ->setParameter('sub', $subscription)
            ->setParameter('open', [
                SaasInvoiceStatus::DRAFT->value,
                SaasInvoiceStatus::PENDING->value,
            ])
            ->orderBy('i.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByPaydunyaToken(string $token): ?SaasInvoice
    {
        return $this->findOneBy(['paydunyaToken' => $token]);
    }

    /**
     * Génère un numéro de facture du format SAAS-YYYY-NNNNN.
     */
    public function generateNextNumber(\DateTimeImmutable $now): string
    {
        $year = (int) $now->format('Y');

        $count = (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.number LIKE :prefix')
            ->setParameter('prefix', sprintf('SAAS-%d-%%', $year))
            ->getQuery()
            ->getSingleScalarResult();

        return sprintf('SAAS-%d-%05d', $year, $count + 1);
    }
}
