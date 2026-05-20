<?php

namespace App\Hotel\Billing\Infrastructure\Repository;

use App\Hotel\Billing\Domain\Entity\Invoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * Génère un numéro de facture unique : FAC-YYYY-NNNNN
     */
    public function generateInvoiceNumber(): string
    {
        $year = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')))->format('Y');
        $prefix = 'FAC-' . $year . '-';

        $lastNumber = $this->createQueryBuilder('i')
            ->select('i.number')
            ->where('i.number LIKE :prefix')
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('i.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($lastNumber === null) {
            return $prefix . '00001';
        }

        $sequence = (int) substr($lastNumber['number'], strlen($prefix));

        return $prefix . str_pad((string) ($sequence + 1), 5, '0', STR_PAD_LEFT);
    }
}
