<?php

namespace App\Platform\Tenant\Domain\Service;

use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Crée le schema PostgreSQL hotel_{uuid} et initialise les tables de base.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly Connection      $connection,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Provisionne le schema d'un tenant :
     * 1. Crée le schema hotel_{uuid}
     * 2. Crée la table staff_users (nécessaire dès l'onboarding)
     */
    public function provision(Tenant $tenant): void
    {
        $schemaName = $tenant->getSchemaName();

        if (!preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            throw new \RuntimeException(sprintf('Nom de schema invalide : %s', $schemaName));
        }

        $this->connection->executeStatement(
            sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schemaName)
        );

        // Table staff_users — nécessaire pour l'onboarding du Manager
        // Les autres tables métier sont créées via les migrations tenant (Sprint 3)
        $this->connection->executeStatement(sprintf('
            CREATE TABLE IF NOT EXISTS %s.staff_users (
                id            UUID         NOT NULL,
                email         VARCHAR(180) NOT NULL,
                password      VARCHAR(255) NOT NULL,
                first_name    VARCHAR(100) NOT NULL,
                last_name     VARCHAR(100) NOT NULL,
                role          VARCHAR(20)  NOT NULL DEFAULT \'RECEPTIONIST\'
                                  CHECK (role IN (\'MANAGER\',\'RECEPTIONIST\',\'HOUSEKEEPER\',\'ACCOUNTANT\')),
                phone         VARCHAR(20)           DEFAULT NULL,
                active        BOOLEAN      NOT NULL DEFAULT TRUE,
                locale        VARCHAR(5)   NOT NULL DEFAULT \'fr\',
                last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                created_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at    TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
        ', $schemaName));

        $this->connection->executeStatement(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS uniq_staff_users_email ON %s.staff_users (email)',
            $schemaName
        ));

        $this->logger->info('Schema hotel_{uuid} créé pour tenant {slug}', [
            'schema' => $schemaName,
            'slug'   => $tenant->getSlug(),
        ]);
    }
}
