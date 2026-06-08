<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Crée la table `staff_invitations` dans le schema
 * hotel_{uuid}.
 *
 * Sprint 13bis : un manager peut inviter ses employés par email. Le
 * token n'est jamais stocké en clair, seul son SHA-256 est en BDD.
 *
 * Indexes :
 *  - idx_staff_invitation_token (token_hash) : lookup à l'acceptation.
 *  - idx_staff_invitation_email_status : check « invitation pending
 *    déjà émise pour cet email » avant d'en émettre une nouvelle.
 *
 * CHECK constraint : statuts limités aux 4 valeurs autorisées.
 */
final class Version20260607000000AddStaffInvitations implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260607000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS staff_invitations (
                id           UUID         NOT NULL,
                email        VARCHAR(180) NOT NULL,
                first_name   VARCHAR(100) NOT NULL,
                last_name    VARCHAR(100) NOT NULL,
                role         VARCHAR(20)  NOT NULL,
                token_hash   VARCHAR(64)  NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
                invited_by   UUID                  DEFAULT NULL,
                expires_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                created_at   TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                accepted_at  TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                revoked_at   TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT staff_invitations_status_check
                    CHECK (status IN ('pending','accepted','expired','revoked'))
            )",
            'CREATE INDEX IF NOT EXISTS idx_staff_invitation_token
                ON staff_invitations (token_hash)',
            'CREATE INDEX IF NOT EXISTS idx_staff_invitation_email_status
                ON staff_invitations (email, status)',
        ];
    }
}
