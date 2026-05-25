<?php

namespace App\DataFixtures\Purger;

use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Purger qui purge dynamiquement les seules tables présentes dans
 * le schema public ; les tables tenant sont détectées et ignorées
 * automatiquement via information_schema.
 */
class TenantAwarePurger implements ORMPurgerInterface
{
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

        // 1. Tables mappées par Doctrine (dédoublonnées)
        $mappedTables = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $mappedTables[$metadata->getTableName()] = true;
        }

        // 2. Tables réellement présentes dans le schema public
        $rows = $connection->fetchAllAssociative(
            "SELECT table_name FROM information_schema.tables
             WHERE table_schema = 'public' AND table_type = 'BASE TABLE'"
        );
        $publicTables = [];
        foreach ($rows as $row) {
            $publicTables[$row['table_name']] = true;
        }

        // 3. Intersection : tables Doctrine qui existent dans public
        $tablesToPurge = array_keys(array_intersect_key($mappedTables, $publicTables));

        if (empty($tablesToPurge)) {
            return;
        }

        // Désactiver les contraintes FK temporairement
        $connection->executeStatement('SET session_replication_role = replica');

        try {
            foreach ($tablesToPurge as $table) {
                // Validation du nom de table (sources de confiance, garde par rigueur)
                if (preg_match('/^[a-z_][a-z0-9_]*$/', $table) !== 1) {
                    continue;
                }
                $connection->executeStatement(
                    sprintf('DELETE FROM public.%s', $table)
                );
            }
        } finally {
            $connection->executeStatement('SET session_replication_role = DEFAULT');
        }
    }
}
