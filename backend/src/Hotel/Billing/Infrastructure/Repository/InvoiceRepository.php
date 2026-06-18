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

    /**
     * Factures DRAFT rattachées à des réservations dont le check-out
     * effectif s'est produit à la date donnée. Utilisé par la checklist
     * night audit pour signaler les départs non facturés.
     *
     * @return \App\Hotel\Billing\Domain\Entity\Invoice[]
     */
    public function findDraftForReservationsCheckedOutOn(\DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $start->modify('+1 day');

        return $this->createQueryBuilder('i')
            ->addSelect('r')
            ->leftJoin('i.reservation', 'r')
            ->where('i.status = :draftStatus')
            ->andWhere('r.status = :resStatus')
            ->andWhere('r.checkedOutAt >= :start')
            ->andWhere('r.checkedOutAt < :end')
            ->setParameter('draftStatus', 'draft')
            ->setParameter('resStatus', 'checked_out')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('i.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptage, somme TTC et somme TVA des factures émises (issuedAt)
     * pour un jour donné. Ignore drafts et annulées.
     *
     * @return array{count: int, total: string, vat: string}
     */
    public function countAndSumIssuedForDate(\DateTimeImmutable $date): array
    {
        $start = $date->setTime(0, 0, 0);
        $end   = $start->modify('+1 day');

        $row = $this->createQueryBuilder('i')
            ->select(
                'COUNT(i.id) AS cnt',
                'COALESCE(SUM(i.totalXof), 0) AS total',
                'COALESCE(SUM(i.taxXof), 0) AS vat',
            )
            ->where('i.issuedAt IS NOT NULL')
            ->andWhere('i.issuedAt >= :start')
            ->andWhere('i.issuedAt < :end')
            ->andWhere('i.status <> :cancelled')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('cancelled', 'cancelled')
            ->getQuery()
            ->getSingleResult();

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'total' => number_format((float) ($row['total'] ?? 0), 2, '.', ''),
            'vat'   => number_format((float) ($row['vat']   ?? 0), 2, '.', ''),
        ];
    }
}
