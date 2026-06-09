<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Sprint 13quater-A.
 *
 * Table `daily_closes` : night audit / clôture comptable journalière.
 * Une ligne par business date close ; la contrainte UNIQUE sur
 * `business_date` garantit l'unicité (une réouverture + reclôture
 * fait un UPDATE sur la même ligne, pas un INSERT).
 *
 * `closed_by_id` non FK-contraint sur staff_users : on garde l'audit
 * même si le staff est supprimé (cohérent avec audit_logs).
 */
final class Version20260610000000CreateDailyCloses implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260610000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS daily_closes (
                id                UUID                          NOT NULL,
                business_date     DATE                          NOT NULL,
                closed_at         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                closed_by_id      UUID                          NOT NULL,
                closed_by_email   VARCHAR(180)                  NOT NULL,
                cutoff_hour       SMALLINT                      NOT NULL,
                snapshot          JSONB                         NOT NULL,
                reopened_at       TIMESTAMP(0) WITHOUT TIME ZONE,
                reopened_by_id    UUID,
                reopened_by_email VARCHAR(180),
                reopen_reason     TEXT,
                created_at        TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )',

            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_daily_closes_business_date
                ON daily_closes (business_date)',

            'CREATE INDEX IF NOT EXISTS idx_daily_closes_closed_at
                ON daily_closes (closed_at DESC)',
        ];
    }
}
