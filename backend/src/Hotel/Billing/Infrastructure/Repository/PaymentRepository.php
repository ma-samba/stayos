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

    /**
     * Somme des paiements PAID pour une date donnée, groupée par méthode.
     *
     * Filtre simple V1 : DATE(paidAt) = $date (timezone du serveur).
     * Un paiement enregistré en pleine nuit avant le cutoff sera donc
     * comptabilisé sur la date civile, pas sur la business date — limite
     * V1, à durcir si nécessaire.
     *
     * @return array<string, string> ex: ['wave' => '125000.00', 'cash' => '10000.00']
     */
    public function sumPaidByMethodForDate(\DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $start->modify('+1 day');

        $rows = $this->createQueryBuilder('p')
            ->select('p.method AS method, SUM(p.amountXof) AS total')
            ->where('p.status = :status')
            ->andWhere('p.paidAt >= :start')
            ->andWhere('p.paidAt < :end')
            ->setParameter('status', 'paid')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->groupBy('p.method')
            ->getQuery()
            ->getArrayResult();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['method']] = number_format((float) ($row['total'] ?? 0), 2, '.', '');
        }

        return $result;
    }
}
