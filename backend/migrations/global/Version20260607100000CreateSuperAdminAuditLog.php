<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration Sprint 13bis-B — Audit log dédié aux actions SuperAdmin
 * dans le schema `public`.
 *
 * Différent de l'audit log tenant (`audit_logs` dans chaque schema
 * hotel_{uuid}) car :
 *  - L'acteur est un User platform (table public.users), pas un StaffUser.
 *  - Il n'y a pas forcément de tenant cible (ex: listing).
 *  - Capture systématique IP + User-Agent (le SuperAdmin agit
 *    toujours via HTTP).
 */
final class Version20260607100000CreateSuperAdminAuditLog extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 13bis-B — Table public.superadmin_audit_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.superadmin_audit_log (
                id            UUID          NOT NULL,
                actor_email   VARCHAR(180)  NOT NULL,
                tenant_slug   VARCHAR(80)            DEFAULT NULL,
                action        VARCHAR(60)   NOT NULL,
                payload       JSONB                  DEFAULT NULL,
                ip_address    VARCHAR(45)            DEFAULT NULL,
                user_agent    TEXT                   DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id)
            )
        ');

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sa_audit_tenant
            ON public.superadmin_audit_log (tenant_slug)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sa_audit_actor
            ON public.superadmin_audit_log (actor_email)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_sa_audit_date
            ON public.superadmin_audit_log (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS public.superadmin_audit_log');
    }
}
