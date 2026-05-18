<?php

namespace App\Hotel\Billing\Infrastructure\Repository;

use App\Hotel\Billing\Domain\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * Retourne la somme des paiements pour une facture donnée.
     */
    public function getTotalPaidForInvoice(string $invoiceId): string
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amountXof)')
            ->where('p.invoice = :invoiceId')
            ->setParameter('invoiceId', $invoiceId)
            ->getQuery()
            ->getSingleScalarResult();

        return $result ? number_format((float) $result, 2, '.', '') : '0.00';
    }
}
