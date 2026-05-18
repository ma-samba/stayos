<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale — Crée la table public.tenants.
 *
 * Cette table appartient au schema global (public) et est partagée
 * par toute la plateforme SaaS. Elle n'est jamais dans un schema hotel_{uuid}.
 */
final class Version20260512000000CreateTenantsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table tenants dans le schema public';
    }

    public function up(Schema $schema): void
    {
        // Extension UUID nécessaire pour uuid_generate_v4()
        $this->addSql('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');

        $this->addSql('
            CREATE TABLE IF NOT EXISTS public.tenants (
                id          UUID         NOT NULL,
                slug        VARCHAR(100) NOT NULL,
                name        VARCHAR(255) NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT \'trial\'
                                CHECK (status IN (\'trial\', \'active\', \'suspended\', \'churned\')),
                subdomain   VARCHAR(100) NOT NULL,
                timezone    VARCHAR(50)  NOT NULL DEFAULT \'Africa/Dakar\',
                country     VARCHAR(2)   NOT NULL DEFAULT \'SN\',
                currency    VARCHAR(3)   NOT NULL DEFAULT \'XOF\',
                settings    JSON                  DEFAULT NULL,
                created_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        ');

        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_tenants_slug      ON public.tenants (slug)'
        );
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_tenants_subdomain ON public.tenants (subdomain)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS public.tenants');
    }
}
