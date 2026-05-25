<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Crée les tables seasonal_rates et promotions.
 *
 * Sprint 9 : Tarifs & promotions.
 */
final class Version20260524000000CreateRateEntities implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260524000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            // ── seasonal_rates ──
            "CREATE TABLE IF NOT EXISTS seasonal_rates (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                hotel_id UUID NOT NULL,
                room_type_id UUID DEFAULT NULL,
                name VARCHAR(150) NOT NULL,
                type VARCHAR(20) NOT NULL,
                value DECIMAL(10,2) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                priority INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id),
                CONSTRAINT fk_seasonal_rates_hotel FOREIGN KEY (hotel_id) REFERENCES hotel_profile(id) ON DELETE CASCADE,
                CONSTRAINT fk_seasonal_rates_room_type FOREIGN KEY (room_type_id) REFERENCES room_types(id) ON DELETE SET NULL,
                CONSTRAINT seasonal_rates_type_check CHECK (type IN ('multiplier','absolute'))
            )",

            // ── promotions ──
            "CREATE TABLE IF NOT EXISTS promotions (
                id UUID NOT NULL DEFAULT gen_random_uuid(),
                hotel_id UUID NOT NULL,
                code VARCHAR(50) NOT NULL,
                description VARCHAR(255) DEFAULT NULL,
                type VARCHAR(20) NOT NULL,
                value DECIMAL(10,2) NOT NULL,
                max_discount_xof DECIMAL(10,2) DEFAULT NULL,
                min_nights INT NOT NULL DEFAULT 1,
                min_amount_xof DECIMAL(10,2) DEFAULT NULL,
                valid_from DATE DEFAULT NULL,
                valid_to DATE DEFAULT NULL,
                max_uses INT DEFAULT NULL,
                used_count INT NOT NULL DEFAULT 0,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                applicable_room_type_ids JSONB DEFAULT NULL,
                applicable_rate_plan_ids JSONB DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_promotions_hotel FOREIGN KEY (hotel_id) REFERENCES hotel_profile(id) ON DELETE CASCADE,
                CONSTRAINT promotions_type_check CHECK (type IN ('percentage','fixed'))
            )",
        ];
    }
}
