<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Ajoute le champ callback_secret à la table payments.
 *
 * Utilisé pour vérifier l'authenticité des IPN Paydunya (timing-safe).
 */
final class Version20260520160000AddPaymentCallbackSecret implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260520160000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS callback_secret VARCHAR(64) DEFAULT NULL',
        ];
    }
}
