<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Ajoute la colonne document_url à la table guests.
 *
 * Exécutée par TenantMigrator sur chaque schema hotel_{uuid}.
 */
final class Version20260520140000AddGuestDocumentUrl implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260520140000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'ALTER TABLE guests ADD COLUMN IF NOT EXISTS document_url VARCHAR(500) DEFAULT NULL',
        ];
    }
}
