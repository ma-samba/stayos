<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Crée toutes les tables métier dans le schema hotel_{uuid}.
 *
 * Ce fichier n'est PAS exécuté via doctrine:migrations:migrate (qui gère le schema public).
 * Il est exécuté par TenantMigrator qui positionne le search_path
 * avant d'exécuter chaque instruction SQL.
 *
 * Convention : utiliser le nom de table sans préfixe de schema — le search_path s'en charge.
 */
final class Version20260514000000CreateHotelTables implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260514000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            // ── HotelProfile ──
            "CREATE TABLE IF NOT EXISTS hotel_profile (
                id               UUID         NOT NULL,
                name             VARCHAR(255) NOT NULL,
                address          VARCHAR(255)          DEFAULT NULL,
                city             VARCHAR(100)          DEFAULT NULL,
                country          VARCHAR(2)   NOT NULL DEFAULT 'SN',
                phone            VARCHAR(20)           DEFAULT NULL,
                email            VARCHAR(180)          DEFAULT NULL,
                star_rating      SMALLINT              DEFAULT NULL,
                total_rooms      INT          NOT NULL DEFAULT 0,
                check_in_time    VARCHAR(5)   NOT NULL DEFAULT '14:00',
                check_out_time   VARCHAR(5)   NOT NULL DEFAULT '11:00',
                logo_url         VARCHAR(255)          DEFAULT NULL,
                cover_photo_url  VARCHAR(255)          DEFAULT NULL,
                settings         JSON                  DEFAULT NULL,
                created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )",

            // ── Floors ──
            "CREATE TABLE IF NOT EXISTS floors (
                id       UUID         NOT NULL,
                number   INT          NOT NULL,
                name     VARCHAR(100)          DEFAULT NULL,
                active   BOOLEAN      NOT NULL DEFAULT TRUE,
                PRIMARY KEY (id)
            )",

            // ── RoomTypes ──
            "CREATE TABLE IF NOT EXISTS room_types (
                id                UUID          NOT NULL,
                name              VARCHAR(100)  NOT NULL,
                description       TEXT                   DEFAULT NULL,
                base_rate_xof     DECIMAL(10,2) NOT NULL,
                max_occupancy     INT           NOT NULL DEFAULT 2,
                bed_configuration JSON                   DEFAULT NULL,
                amenities         JSON                   DEFAULT NULL,
                sort_order        INT           NOT NULL DEFAULT 0,
                PRIMARY KEY (id)
            )",

            // ── Rooms ──
            "CREATE TABLE IF NOT EXISTS rooms (
                id         UUID        NOT NULL,
                floor_id   UUID                 DEFAULT NULL,
                type_id    UUID        NOT NULL,
                number     VARCHAR(20) NOT NULL,
                status     VARCHAR(20) NOT NULL DEFAULT 'available'
                               CHECK (status IN ('available','occupied','cleaning','maintenance','out_of_order')),
                notes      TEXT                 DEFAULT NULL,
                is_active  BOOLEAN     NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_room_floor FOREIGN KEY (floor_id) REFERENCES floors (id) ON DELETE SET NULL,
                CONSTRAINT fk_room_type  FOREIGN KEY (type_id)  REFERENCES room_types (id)
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_room_number ON rooms (number)",

            // ── Guests ──
            "CREATE TABLE IF NOT EXISTS guests (
                id              UUID         NOT NULL,
                first_name      VARCHAR(100) NOT NULL,
                last_name       VARCHAR(100) NOT NULL,
                email           VARCHAR(180)          DEFAULT NULL,
                phone           VARCHAR(20)           DEFAULT NULL,
                nationality     VARCHAR(2)            DEFAULT NULL,
                document_type   VARCHAR(30)           DEFAULT NULL,
                document_number VARCHAR(60)           DEFAULT NULL,
                date_of_birth   DATE                  DEFAULT NULL,
                address         VARCHAR(255)          DEFAULT NULL,
                city            VARCHAR(100)          DEFAULT NULL,
                country         VARCHAR(2)            DEFAULT NULL,
                preferences     JSON                  DEFAULT NULL,
                total_stays     INT          NOT NULL DEFAULT 0,
                created_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at      TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )",

            // ── Reservations ──
            "CREATE TABLE IF NOT EXISTS reservations (
                id                  UUID         NOT NULL,
                confirmation_number VARCHAR(30)  NOT NULL,
                guest_id            UUID         NOT NULL,
                room_id             UUID         NOT NULL,
                status              VARCHAR(20)  NOT NULL DEFAULT 'confirmed'
                                        CHECK (status IN ('confirmed','pending','checked_in','checked_out','cancelled','no_show')),
                check_in            DATE         NOT NULL,
                check_out           DATE         NOT NULL,
                adults              INT          NOT NULL DEFAULT 1,
                children            INT          NOT NULL DEFAULT 0,
                rate_xof            DECIMAL(10,2) NOT NULL,
                total_xof           DECIMAL(10,2) NOT NULL,
                source              VARCHAR(20)  NOT NULL DEFAULT 'direct'
                                        CHECK (source IN ('direct','booking_com','airbnb','expedia','walk_in')),
                deposit_xof         DECIMAL(10,2)         DEFAULT NULL,
                notes               TEXT                  DEFAULT NULL,
                special_requests    TEXT                  DEFAULT NULL,
                checked_in_at       TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                checked_out_at      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at          TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_reservation_guest FOREIGN KEY (guest_id) REFERENCES guests (id),
                CONSTRAINT fk_reservation_room  FOREIGN KEY (room_id)  REFERENCES rooms (id)
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_reservation_confirmation ON reservations (confirmation_number)",
            "CREATE INDEX IF NOT EXISTS idx_reservation_checkin  ON reservations (check_in)",
            "CREATE INDEX IF NOT EXISTS idx_reservation_checkout ON reservations (check_out)",
            "CREATE INDEX IF NOT EXISTS idx_reservation_status   ON reservations (status)",

            // ── Invoices ──
            "CREATE TABLE IF NOT EXISTS invoices (
                id             UUID          NOT NULL,
                reservation_id UUID          NOT NULL,
                number         VARCHAR(30)   NOT NULL,
                status         VARCHAR(20)   NOT NULL DEFAULT 'draft'
                                   CHECK (status IN ('draft','issued','paid','partial','cancelled')),
                subtotal_xof   DECIMAL(10,2) NOT NULL,
                tax_rate       DECIMAL(10,2) NOT NULL DEFAULT 18.00,
                tax_xof        DECIMAL(10,2) NOT NULL,
                total_xof      DECIMAL(10,2) NOT NULL,
                notes          TEXT                   DEFAULT NULL,
                issued_at      TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                due_at         TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at     TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_invoice_reservation FOREIGN KEY (reservation_id) REFERENCES reservations (id)
            )",
            "CREATE UNIQUE INDEX IF NOT EXISTS uniq_invoice_number ON invoices (number)",

            // ── InvoiceLines ──
            "CREATE TABLE IF NOT EXISTS invoice_lines (
                id             UUID          NOT NULL,
                invoice_id     UUID          NOT NULL,
                label          VARCHAR(255)  NOT NULL,
                quantity       INT           NOT NULL DEFAULT 1,
                unit_price_xof DECIMAL(10,2) NOT NULL,
                total_xof      DECIMAL(10,2) NOT NULL,
                sort_order     INT           NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
            )",

            // ── Payments ──
            "CREATE TABLE IF NOT EXISTS payments (
                id           UUID          NOT NULL,
                invoice_id   UUID          NOT NULL,
                method       VARCHAR(20)   NOT NULL
                                 CHECK (method IN ('cash','wave','orange_money','card','bank_transfer','ota')),
                amount_xof   DECIMAL(10,2) NOT NULL,
                reference    VARCHAR(100)           DEFAULT NULL,
                processed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                notes        TEXT                   DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id)
            )",

            // ── CleaningTasks ──
            "CREATE TABLE IF NOT EXISTS cleaning_tasks (
                id           UUID        NOT NULL,
                room_id      UUID        NOT NULL,
                assigned_to  UUID                 DEFAULT NULL,
                status       VARCHAR(20) NOT NULL DEFAULT 'pending'
                                 CHECK (status IN ('pending','in_progress','done','inspected','skipped')),
                type         VARCHAR(20) NOT NULL DEFAULT 'stay_over'
                                 CHECK (type IN ('departure','stay_over','inspection','maintenance')),
                scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                started_at   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                notes        TEXT                 DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_cleaning_room  FOREIGN KEY (room_id)    REFERENCES rooms (id),
                CONSTRAINT fk_cleaning_staff FOREIGN KEY (assigned_to) REFERENCES staff_users (id) ON DELETE SET NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_cleaning_scheduled ON cleaning_tasks (scheduled_at)",

            // ── RatePlans ──
            "CREATE TABLE IF NOT EXISTS rate_plans (
                id            UUID          NOT NULL,
                hotel_id      UUID          NOT NULL,
                room_type_id  UUID                   DEFAULT NULL,
                name          VARCHAR(150)  NOT NULL,
                base_rate_xof DECIMAL(10,2) NOT NULL,
                min_nights    INT           NOT NULL DEFAULT 1,
                conditions    JSON                   DEFAULT NULL,
                is_active     BOOLEAN       NOT NULL DEFAULT TRUE,
                valid_from    DATE                   DEFAULT NULL,
                valid_to      DATE                   DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_rate_plan_hotel     FOREIGN KEY (hotel_id)     REFERENCES hotel_profile (id) ON DELETE CASCADE,
                CONSTRAINT fk_rate_plan_room_type FOREIGN KEY (room_type_id) REFERENCES room_types (id) ON DELETE SET NULL
            )",

            // ── AuditLogs ──
            "CREATE TABLE IF NOT EXISTS audit_logs (
                id               UUID         NOT NULL,
                staff_user_email VARCHAR(180)          DEFAULT NULL,
                staff_user_role  VARCHAR(20)           DEFAULT NULL,
                action           VARCHAR(100) NOT NULL,
                entity_type      VARCHAR(100) NOT NULL,
                entity_id        VARCHAR(100) NOT NULL,
                before           JSON                  DEFAULT NULL,
                after            JSON                  DEFAULT NULL,
                ip_address       VARCHAR(45)           DEFAULT NULL,
                user_agent       VARCHAR(255)          DEFAULT NULL,
                created_at       TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )",
            "CREATE INDEX IF NOT EXISTS idx_audit_entity     ON audit_logs (entity_type, entity_id)",
            "CREATE INDEX IF NOT EXISTS idx_audit_action     ON audit_logs (action)",
            "CREATE INDEX IF NOT EXISTS idx_audit_staff_email ON audit_logs (staff_user_email)",
        ];
    }
}
