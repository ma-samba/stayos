<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Sprint 13ter.
 *
 * Durcit la configuration de l'hôtel pour permettre un CRUD propre
 * côté manager :
 *  - unique index sur `floors.number` (sinon deux étages "2" possibles)
 *  - colonnes timestamps sur `floors` et `room_types` pour pouvoir
 *    auditer création/modification depuis l'UI de configuration.
 *
 * Backfill puis NOT NULL — pas de TIMESTAMP avec DEFAULT NOW() pour
 * coller au reste du schéma (colonnes gérées par Doctrine).
 */
final class Version20260608000000HardenFloorsAndAuditConfig implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260608000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_floors_number
                ON floors (number)',

            'ALTER TABLE floors      ADD COLUMN IF NOT EXISTS created_at TIMESTAMP(0) WITHOUT TIME ZONE',
            'ALTER TABLE floors      ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE',
            'ALTER TABLE room_types  ADD COLUMN IF NOT EXISTS created_at TIMESTAMP(0) WITHOUT TIME ZONE',
            'ALTER TABLE room_types  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP(0) WITHOUT TIME ZONE',

            'UPDATE floors      SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL',
            'UPDATE room_types  SET created_at = NOW(), updated_at = NOW() WHERE created_at IS NULL',

            'ALTER TABLE floors      ALTER COLUMN created_at SET NOT NULL',
            'ALTER TABLE floors      ALTER COLUMN updated_at SET NOT NULL',
            'ALTER TABLE room_types  ALTER COLUMN created_at SET NOT NULL',
            'ALTER TABLE room_types  ALTER COLUMN updated_at SET NOT NULL',
        ];
    }
}
