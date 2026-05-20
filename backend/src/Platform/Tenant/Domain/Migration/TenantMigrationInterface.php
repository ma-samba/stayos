<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Domain\Migration;

/**
 * Interface pour les migrations tenant (schema hotel_{uuid}).
 *
 * Chaque migration tenant implémente cette interface.
 * Le TenantMigrator les découvre et applique les manquantes par schema.
 */
interface TenantMigrationInterface
{
    /**
     * Identifiant unique de version (ex: '20260514000000').
     * Sert de clé dans la table de tracking tenant_migrations.
     */
    public function getVersion(): string;

    /**
     * Instructions SQL à exécuter dans le schema tenant.
     * Le search_path est déjà positionné sur le schema avant l'appel.
     *
     * @return string[]
     */
    public function getStatements(): array;
}
