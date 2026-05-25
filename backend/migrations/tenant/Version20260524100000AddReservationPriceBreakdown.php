<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Ajoute la colonne price_breakdown (JSONB) aux reservations.
 *
 * Sprint 9 : Branchement PriceCalculator dans le flux de réservation.
 */
final class Version20260524100000AddReservationPriceBreakdown implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260524100000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'ALTER TABLE reservations ADD COLUMN IF NOT EXISTS price_breakdown JSONB DEFAULT NULL',
        ];
    }
}
