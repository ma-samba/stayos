<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Domain\Migration;

use DoctrineMigrations\Tenant\Version20260514000000CreateHotelTables;
use DoctrineMigrations\Tenant\Version20260520140000AddGuestDocumentUrl;
use DoctrineMigrations\Tenant\Version20260520150000AddPaymentGatewayFields;
use DoctrineMigrations\Tenant\Version20260520160000AddPaymentCallbackSecret;
use DoctrineMigrations\Tenant\Version20260520170000UpdatePaymentMethodCheck;
use DoctrineMigrations\Tenant\Version20260524000000CreateRateEntities;
use DoctrineMigrations\Tenant\Version20260524100000AddReservationPriceBreakdown;

/**
 * Registre ordonné de toutes les migrations tenant.
 *
 * IMPORTANT : ajouter chaque nouvelle migration ici, dans l'ordre chronologique.
 */
class TenantMigrationRegistry
{
    /** @var TenantMigrationInterface[] */
    private array $migrations;

    public function __construct()
    {
        $this->migrations = [
            new Version20260514000000CreateHotelTables(),
            new Version20260520140000AddGuestDocumentUrl(),
            new Version20260520150000AddPaymentGatewayFields(),
            new Version20260520160000AddPaymentCallbackSecret(),
            new Version20260520170000UpdatePaymentMethodCheck(),
            new Version20260524000000CreateRateEntities(),
            new Version20260524100000AddReservationPriceBreakdown(),
        ];
    }

    /**
     * @return TenantMigrationInterface[]
     */
    public function all(): array
    {
        return $this->migrations;
    }

    /**
     * Retourne les migrations dont la version n'est pas dans $appliedVersions.
     *
     * @param string[] $appliedVersions
     * @return TenantMigrationInterface[]
     */
    public function pending(array $appliedVersions): array
    {
        return array_values(array_filter(
            $this->migrations,
            fn (TenantMigrationInterface $m) => !in_array($m->getVersion(), $appliedVersions, true),
        ));
    }
}
