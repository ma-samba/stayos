<?php

declare(strict_types=1);

namespace DoctrineMigrations\Tenant;

use App\Platform\Tenant\Domain\Migration\TenantMigrationInterface;

/**
 * Migration tenant — Sprint 14-B.2.3.
 *
 * Indexes composites ciblés pour les requêtes chaudes analytics +
 * night audit. Les index simples existants sur `reservations`
 * (check_in seul, check_out seul, status seul) couvrent les filtres
 * unidimensionnels mais le planner PostgreSQL préfère un composite
 * (status, check_in/out) pour les requêtes qui combinent les deux
 * — typique des KPIs (arrivées/départs du jour) et du night audit.
 *
 * Tous les index sont créés en IF NOT EXISTS (idempotent, cohérent
 * avec le reste du registry).
 */
final class Version20260618000000AddAnalyticsIndexes implements TenantMigrationInterface
{
    public function getVersion(): string
    {
        return '20260618000000';
    }

    /**
     * @return string[]
     */
    public function getStatements(): array
    {
        return [
            // AnalyticsRepository::arrivalsForDay()
            // WHERE status = :s AND check_in = :day
            'CREATE INDEX IF NOT EXISTS idx_reservation_status_checkin
                ON reservations (status, check_in)',

            // AnalyticsRepository::departuresForDay()
            // WHERE status = :s AND check_out = :day
            // + reservationsIntersectingPeriod (status NOT IN + check_out > :y)
            'CREATE INDEX IF NOT EXISTS idx_reservation_status_checkout
                ON reservations (status, check_out)',

            // PaymentRepository::sumPaidByMethodForDate() (night audit)
            // WHERE processed_at::date = :day GROUP BY method
            'CREATE INDEX IF NOT EXISTS idx_payment_processed_at
                ON payments (processed_at)',

            // InvoiceRepository::countAndSumIssuedForDate() (night audit)
            // WHERE status = \'issued\' AND issued_at::date = :day
            'CREATE INDEX IF NOT EXISTS idx_invoice_status_issued_at
                ON invoices (status, issued_at)',
        ];
    }
}
