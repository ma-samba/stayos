<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Ajoute les champs gateway/statut à la table payments.
 *
 * Exécutée par TenantMigrator sur chaque schema hotel_{uuid}.
 */
final class Version20260520150000AddPaymentGatewayFields implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260520150000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            "ALTER TABLE payments ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'init'",
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway_token VARCHAR(255) DEFAULT NULL',
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS gateway_name VARCHAR(30) DEFAULT NULL',
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS raw_payload JSONB DEFAULT NULL',
            'ALTER TABLE payments ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
            'CREATE INDEX IF NOT EXISTS idx_payment_gateway_token ON payments (gateway_token)',
        ];
    }
}
