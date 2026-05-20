<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Domain\Service;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;
use App\Platform\Tenant\Domain\Migration\TenantMigrationRegistry;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Applique les migrations tenant manquantes sur un schema hotel_{uuid}.
 *
 * Table de tracking : tenant_migrations (version, applied_at) — créée par schema.
 */
class TenantMigrator
{
    private const SCHEMA_PATTERN = '/^hotel_[0-9a-f_]+$/i';

    public function __construct(
        private readonly Connection               $connection,
        private readonly TenantMigrationRegistry  $registry,
        private readonly LoggerInterface           $logger,
    ) {}

    /**
     * Applique les migrations manquantes sur le schema donné.
     *
     * @return string[] Versions appliquées
     */
    public function migrate(string $schemaName): array
    {
        $this->assertValidSchema($schemaName);
        $this->ensureTrackingTable($schemaName);

        $applied = $this->getAppliedVersions($schemaName);
        $pending = $this->registry->pending($applied);

        if (count($pending) === 0) {
            return [];
        }

        $appliedNow = [];

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            foreach ($pending as $migration) {
                $this->applyMigration($schemaName, $migration);
                $appliedNow[] = $migration->getVersion();

                $this->logger->info('Tenant migration applied', [
                    'schema'  => $schemaName,
                    'version' => $migration->getVersion(),
                ]);
            }
        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }

        return $appliedNow;
    }

    /**
     * Retourne les migrations en attente pour un schema (lecture seule, aucune écriture).
     *
     * @return TenantMigrationInterface[]
     */
    public function getPending(string $schemaName): array
    {
        $this->assertValidSchema($schemaName);

        return $this->registry->pending(
            $this->getAppliedVersions($schemaName)
        );
    }

    private function assertValidSchema(string $schemaName): void
    {
        if (!preg_match(self::SCHEMA_PATTERN, $schemaName)) {
            throw new \InvalidArgumentException(
                sprintf('Nom de schema tenant invalide : %s', $schemaName)
            );
        }
    }

    private function applyMigration(string $schemaName, TenantMigrationInterface $migration): void
    {
        foreach ($migration->getStatements() as $sql) {
            $this->connection->executeStatement($sql);
        }

        $this->connection->executeStatement(
            sprintf(
                "INSERT INTO %s.tenant_migrations (version, applied_at) VALUES (:version, NOW())",
                $schemaName
            ),
            ['version' => $migration->getVersion()]
        );
    }

    /**
     * @return string[]
     */
    private function getAppliedVersions(string $schemaName): array
    {
        $exists = $this->connection->fetchOne(
            "SELECT to_regclass(:qualified)",
            ['qualified' => $schemaName . '.tenant_migrations']
        );

        if (!$exists) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT version FROM %s.tenant_migrations ORDER BY version', $schemaName)
        );

        return array_column($rows, 'version');
    }

    private function ensureTrackingTable(string $schemaName): void
    {
        $this->connection->executeStatement(sprintf(
            'CREATE TABLE IF NOT EXISTS %s.tenant_migrations (
                version    VARCHAR(30) NOT NULL,
                applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (version)
            )',
            $schemaName
        ));
    }
}
