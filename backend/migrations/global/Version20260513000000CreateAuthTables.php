<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration Sprint 2 — Crée les tables d'auth dans le schema public :
 * users, plans, subscriptions, otp_tokens.
 */
final class Version20260513000000CreateAuthTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables auth (users, plans, subscriptions, otp_tokens) dans le schema public';
    }

    public function up(Schema $schema): void
    {
        // ── users ──────────────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.users (
                id            UUID         NOT NULL,
                email         VARCHAR(180) NOT NULL,
                roles         JSON         NOT NULL,
                password      VARCHAR(255) NOT NULL,
                name          VARCHAR(255) NOT NULL,
                active        BOOLEAN      NOT NULL DEFAULT TRUE,
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        ');
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_email ON public.users (email)'
        );

        // ── plans ─────────────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.plans (
                id        SERIAL       NOT NULL,
                name      VARCHAR(20)  NOT NULL
                              CHECK (name IN (\'STARTER\', \'PRO\', \'ENTERPRISE\')),
                price_xof NUMERIC(10,2) NOT NULL,
                price_eur NUMERIC(10,2)          DEFAULT NULL,
                max_rooms INTEGER               DEFAULT NULL,
                max_users INTEGER               DEFAULT NULL,
                features  JSONB        NOT NULL  DEFAULT \'[]\',
                is_active BOOLEAN      NOT NULL  DEFAULT TRUE,
                PRIMARY KEY (id)
            )
        ');

        // ── subscriptions ─────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.subscriptions (
                id                   UUID        NOT NULL,
                tenant_id            UUID        NOT NULL,
                plan_id              INTEGER     NOT NULL,
                status               VARCHAR(20) NOT NULL DEFAULT \'trial\'
                                         CHECK (status IN (\'trial\',\'active\',\'expired\',\'suspended\',\'cancelled\')),
                billing_cycle        VARCHAR(10) NOT NULL DEFAULT \'monthly\'
                                         CHECK (billing_cycle IN (\'monthly\',\'annual\')),
                trial_ends_at        TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                current_period_start TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                current_period_end   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                cancelled_at         TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at           TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_subscriptions_tenant
                    FOREIGN KEY (tenant_id) REFERENCES public.tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_subscriptions_plan
                    FOREIGN KEY (plan_id) REFERENCES public.plans (id)
            )
        ');
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_subscriptions_tenant ON public.subscriptions (tenant_id)'
        );

        // ── otp_tokens ────────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.otp_tokens (
                id         SERIAL       NOT NULL,
                email      VARCHAR(180) NOT NULL,
                code       VARCHAR(6)   NOT NULL,
                type       VARCHAR(30)  NOT NULL
                               CHECK (type IN (\'email_verification\',\'password_reset\',\'sensitive_action\')),
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                used_at    TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                attempts   INTEGER      NOT NULL DEFAULT 0,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        ');
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_otp_tokens_email_type ON public.otp_tokens (email, type)'
        );

        // ── Données initiales — plans par défaut ──────────────────────────────
        $this->addSql("
            INSERT INTO public.plans (name, price_xof, price_eur, max_rooms, max_users, features, is_active)
            VALUES
                ('STARTER',    15000.00, NULL,   30,   5,  '[]'::jsonb, TRUE),
                ('PRO',        35000.00, 50.00,  NULL, 20, '[\"channel_manager\",\"advanced_reports\",\"api_access\"]'::jsonb, TRUE),
                ('ENTERPRISE', 75000.00, 110.00, NULL, NULL, '[\"channel_manager\",\"advanced_reports\",\"api_access\",\"multi_property\",\"revenue_management\"]'::jsonb, TRUE)
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS public.otp_tokens');
        $this->addSql('DROP TABLE IF EXISTS public.subscriptions');
        $this->addSql('DROP TABLE IF EXISTS public.plans');
        $this->addSql('DROP TABLE IF EXISTS public.users');
    }
}
