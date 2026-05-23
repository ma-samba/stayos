<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Met à jour la contrainte CHECK sur payments.method
 * pour inclure 'mobile_money' (ajouté à l'enum PHP au Sprint 7).
 */
final class Version20260520170000UpdatePaymentMethodCheck implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260520170000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_method_check',
            "ALTER TABLE payments ADD CONSTRAINT payments_method_check
                CHECK (method IN ('cash','wave','orange_money','card','bank_transfer','mobile_money','ota'))",
        ];
    }
}
