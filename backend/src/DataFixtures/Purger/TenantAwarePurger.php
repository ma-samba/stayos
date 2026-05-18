<?php

namespace App\DataFixtures\Purger;

use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Purger qui n'efface que les tables du schema public.
 * Les tables Hotel (schema hotel_{uuid}) sont ignorées —
 * elles sont supprimées via DROP SCHEMA CASCADE dans les fixtures.
 */
class TenantAwarePurger implements ORMPurgerInterface
{
    // Tables Hotel à exclure du purge (elles n'existent pas dans public)
    private const HOTEL_TABLES = [
        'hotel_profile',
        'floors',
        'room_types',
        'rooms',
        'guests',
        'reservations',
        'invoices',
        'invoice_lines',
        'payments',
        'cleaning_tasks',
        'rate_plans',
        'audit_logs',
        'staff_users',
    ];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function setEntityManager(EntityManagerInterface $em): void
    {
        $this->em = $em;
    }

    public function purge(): void
    {
        $connection = $this->em->getConnection();

        // Récupère toutes les tables mappées par Doctrine
        $classMetadatas = $this->em->getMetadataFactory()->getAllMetadata();

        $tablesToPurge = [];
        foreach ($classMetadatas as $metadata) {
            $tableName = $metadata->getTableName();
            // N'inclure que les tables qui ne sont pas des tables Hotel
            if (!in_array($tableName, self::HOTEL_TABLES, true)) {
                $tablesToPurge[] = $tableName;
            }
        }

        if (empty($tablesToPurge)) {
            return;
        }

        // Désactiver les contraintes FK temporairement
        $connection->executeStatement('SET session_replication_role = replica');

        try {
            foreach ($tablesToPurge as $table) {
                $connection->executeStatement(
                    sprintf('DELETE FROM public.%s', $table)
                );
            }
        } finally {
            $connection->executeStatement('SET session_replication_role = DEFAULT');
        }
    }
}
