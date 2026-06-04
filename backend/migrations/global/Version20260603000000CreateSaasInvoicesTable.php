<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration Sprint 12 — Facturation SaaS et idempotence des relances :
 *  - public.saas_invoices : factures SaaS (l'hôtel paye son abonnement)
 *  - public.subscriptions : colonnes last_notification_sent_at / type
 *    pour éviter le re-spam des emails de relance.
 */
final class Version20260603000000CreateSaasInvoicesTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 12 — Tables saas_invoices + colonnes de relance sur subscriptions';
    }

    public function up(Schema $schema): void
    {
        // ── saas_invoices ─────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.saas_invoices (
                id                   UUID         NOT NULL,
                number               VARCHAR(30)  NOT NULL,
                tenant_id            UUID         NOT NULL,
                subscription_id      UUID         NOT NULL,
                plan_name            VARCHAR(20)  NOT NULL,
                amount_xof           NUMERIC(10,2) NOT NULL,
                status               VARCHAR(20)  NOT NULL DEFAULT \'draft\'
                                         CHECK (status IN (\'draft\',\'pending\',\'paid\',\'failed\',\'cancelled\')),
                period_start         TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                period_end           TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                due_at               TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                paid_at              TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                sent_at              TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                paydunya_token       VARCHAR(100) DEFAULT NULL,
                checkout_url         VARCHAR(500) DEFAULT NULL,
                payment_reference    VARCHAR(100) DEFAULT NULL,
                callback_secret      VARCHAR(64)  DEFAULT NULL,
                created_at           TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_saas_invoices_tenant
                    FOREIGN KEY (tenant_id) REFERENCES public.tenants (id) ON DELETE CASCADE,
                CONSTRAINT fk_saas_invoices_subscription
                    FOREIGN KEY (subscription_id) REFERENCES public.subscriptions (id)
            )
        ');
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_saas_invoices_number ON public.saas_invoices (number)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_saas_invoices_tenant ON public.saas_invoices (tenant_id)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_saas_invoices_subscription ON public.saas_invoices (subscription_id)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_saas_invoices_paydunya_token ON public.saas_invoices (paydunya_token)'
        );

        // ── subscriptions : suivi des relances (idempotence du scheduler) ─────
        $this->addSql('
            ALTER TABLE public.subscriptions
                ADD COLUMN IF NOT EXISTS last_notification_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                ADD COLUMN IF NOT EXISTS last_notification_type    VARCHAR(40)                  DEFAULT NULL
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS public.saas_invoices');
        $this->addSql('
            ALTER TABLE public.subscriptions
                DROP COLUMN IF EXISTS last_notification_sent_at,
                DROP COLUMN IF EXISTS last_notification_type
        ');
    }
}
