<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Reservation;

use App\Hotel\Reservation\Domain\Enum\CancellationPolicy;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — POST /api/reservations/{id}/cancel avec frais
 * et GET /api/reservations/{id}/cancellation-quote.
 *
 * On joue avec la politique d'annulation tenant via PATCH direct sur
 * Tenant.settings pour couvrir les 3 modes (FLEXIBLE / MODERATE / STRICT).
 *
 */
class CancellationWithFeesTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST          = 'savana.localhost';
    private const MANAGER       = 'admin@savana-hotel.sn';
    private const MANAGER_PWD   = 'admin123';

    private const CONFIRMATION_PREFIX = 'TEST-CANCEL-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        self::assertNotNull($tenant);
        $this->schema = $tenant->getSchemaName();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
            $this->setPolicy(CancellationPolicy::FLEXIBLE);
        } finally {
            parent::tearDown();
        }
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "DELETE FROM invoice_lines WHERE invoice_id IN (
                    SELECT id FROM invoices WHERE reservation_id IN (
                        SELECT id FROM reservations
                        WHERE confirmation_number LIKE :p
                    )
                )",
                ['p' => self::CONFIRMATION_PREFIX . '%']
            );
            $this->conn->executeStatement(
                "DELETE FROM invoices WHERE reservation_id IN (
                    SELECT id FROM reservations
                    WHERE confirmation_number LIKE :p
                )",
                ['p' => self::CONFIRMATION_PREFIX . '%']
            );
            $this->conn->executeStatement(
                'DELETE FROM reservations WHERE confirmation_number LIKE :p',
                ['p' => self::CONFIRMATION_PREFIX . '%']
            );
            $this->conn->executeStatement(
                "DELETE FROM audit_logs
                 WHERE action = 'reservation.cancelled'
                    OR action = 'invoice.cancellation_fee_created'"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function setPolicy(CancellationPolicy $policy): void
    {
        // Mise à jour directe en BDD du settings JSONB (schema public).
        $this->conn->executeStatement(
            "UPDATE public.tenants
             SET settings = COALESCE(settings, '{}'::json)::jsonb || :patch::jsonb
             WHERE slug = 'savana'",
            ['patch' => json_encode(['cancellation_policy' => $policy->value])]
        );
        $this->em->clear();
    }

    /**
     * Insère une résa CONFIRMED avec un check-in placé sur le jour
     * civil tel que `hoursBefore` (calculé par
     * ReservationFeeCalculator) tombe dans la bande visée par
     * `$hoursOffset`.
     *
     * ⚠️ La colonne `reservations.check_in` est de type DATE
     * (date_immutable) — elle perd l'heure. Formater
     * `now + Xh` → `Y-m-d` rendrait la valeur lue par Doctrine
     * équivalente à `today + ceil(X/24) jours à minuit`, et donc
     * la bande effective dépendrait de l'heure d'exécution.
     *
     * Solution : on calcule directement le jour cible (en jours
     * civils Dakar) qui garantit la bonne bande, peu importe
     * l'heure courante :
     *   - check_in = today + N jours à minuit
     *   - hoursBefore ∈ [(N-1)*24 + 1, N*24]
     *
     * Donc pour viser :
     *   - "<24h"   → hoursOffset ∈ [1, 23]  → N=1  (demain)
     *   - "24-48h" → hoursOffset ∈ [25, 47] → N=2  (après-demain)
     *   - ">48h"   → hoursOffset ≥ 49       → N≥3
     *
     * On dérive N = max(1, ceil(hoursOffset / 24)). Cf. flakiness
     * corrigée au Sprint 14-A.3 C.1.
     *
     * @return array{id: string, rateXof: string, totalXof: string}
     */
    private function seedReservation(string $marker, int $hoursOffset): array
    {
        $tz       = new \DateTimeZone('Africa/Dakar');
        $today    = new \DateTimeImmutable('today', $tz);
        $daysAhead = max(1, (int) ceil($hoursOffset / 24));
        $checkIn  = $today->modify("+{$daysAhead} days");
        $checkOut = $checkIn->modify('+3 days');
        $rate     = '40000.00';
        $total    = '120000.00';

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $roomId = $this->conn->executeQuery(
                "SELECT r.id FROM rooms r WHERE r.is_active = TRUE ORDER BY r.number ASC LIMIT 1"
            )->fetchOne();
            $guestId = $this->conn->executeQuery('SELECT id FROM guests LIMIT 1')->fetchOne();
            $id = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();

            $this->conn->executeStatement(
                "INSERT INTO reservations
                 (id, confirmation_number, guest_id, room_id, status,
                  check_in, check_out, adults, children, rate_xof, total_xof,
                  source, created_at, updated_at)
                 VALUES (:id, :conf, :guest, :room, 'confirmed', :ci, :co,
                         1, 0, :rate, :total, 'direct', NOW(), NOW())",
                [
                    'id'    => $id,
                    'conf'  => self::CONFIRMATION_PREFIX . $marker,
                    'guest' => $guestId,
                    'room'  => $roomId,
                    'ci'    => $checkIn->format('Y-m-d'),
                    'co'    => $checkOut->format('Y-m-d'),
                    'rate'  => $rate,
                    'total' => $total,
                ]
            );

            return ['id' => (string) $id, 'rateXof' => $rate, 'totalXof' => $total];
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function cancel(string $token, string $id, array $body = []): array
    {
        return $this->apiRequest(
            'POST', "/api/reservations/$id/cancel", self::HOST,
            body:    array_merge(['reason' => 'Test annulation fonctionnel'], $body),
            headers: ['Authorization' => "Bearer $token"]
        );
    }

    // ── FLEXIBLE ───────────────────────────────────────────────

    public function testCancellationFlexibleNeverCharges(): void
    {
        $this->setPolicy(CancellationPolicy::FLEXIBLE);
        $r = $this->seedReservation('FLEX', 5); // 5h avant check-in
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id']);

        $this->assertApiSuccess($resp);
        self::assertSame('0.00', $resp['data']['feeXof']);
        self::assertNull($resp['data']['invoice']);
    }

    // ── STRICT ─────────────────────────────────────────────────

    public function testCancellationStrictAlwaysCharges(): void
    {
        $this->setPolicy(CancellationPolicy::STRICT);
        $r = $this->seedReservation('STR', 240); // 10 jours avant
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id']);

        $this->assertApiSuccess($resp);
        self::assertSame($r['rateXof'], $resp['data']['feeXof']);
        self::assertNotNull($resp['data']['invoice']);
    }

    // ── MODERATE ──────────────────────────────────────────────

    public function testCancellationModerateMoreThan48hIsFree(): void
    {
        $this->setPolicy(CancellationPolicy::MODERATE);
        $r = $this->seedReservation('M48', 72); // 72h
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id']);

        $this->assertApiSuccess($resp);
        self::assertSame('0.00', $resp['data']['feeXof']);
    }

    public function testCancellationModerateBetween24And48hChargesFirstNight(): void
    {
        $this->setPolicy(CancellationPolicy::MODERATE);
        $r = $this->seedReservation('M30', 30); // 30h
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id']);

        $this->assertApiSuccess($resp);
        self::assertSame($r['rateXof'], $resp['data']['feeXof']);
        self::assertNotNull($resp['data']['invoice']);
    }

    public function testCancellationModerateLessThan24hChargesTotal(): void
    {
        $this->setPolicy(CancellationPolicy::MODERATE);
        $r = $this->seedReservation('M12', 12); // 12h
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id']);

        $this->assertApiSuccess($resp);
        self::assertSame($r['totalXof'], $resp['data']['feeXof']);
    }

    // ── Override ───────────────────────────────────────────────

    public function testFeeOverrideUsesProvidedAmount(): void
    {
        $this->setPolicy(CancellationPolicy::MODERATE);
        $r = $this->seedReservation('OVR', 12); // <24h => normalement total
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        // Geste commercial : on n'applique que 10 000
        $resp = $this->cancel($token, $r['id'], ['feeOverrideXof' => '10000']);

        $this->assertApiSuccess($resp);
        self::assertSame('10000.00', $resp['data']['feeXof']);
        self::assertNotNull($resp['data']['invoice']);
        self::assertSame('10000.00', $resp['data']['invoice']['totalXof']);
    }

    public function testFeeOverrideZeroSkipsInvoice(): void
    {
        $this->setPolicy(CancellationPolicy::STRICT);
        $r = $this->seedReservation('OVR0', 240);
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->cancel($token, $r['id'], ['feeOverrideXof' => '0']);

        $this->assertApiSuccess($resp);
        self::assertSame('0.00', $resp['data']['feeXof']);
        self::assertNull($resp['data']['invoice']);
    }

    // ── Quote ──────────────────────────────────────────────────

    public function testGetCancellationQuoteDoesNotMutate(): void
    {
        $this->setPolicy(CancellationPolicy::STRICT);
        $r = $this->seedReservation('Q1', 100);
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->apiRequest(
            'GET', "/api/reservations/{$r['id']}/cancellation-quote", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('strict', $resp['data']['policy']);
        self::assertSame($r['rateXof'], $resp['data']['amountXof']);
        self::assertIsInt($resp['data']['hoursBefore']);

        // Vérif que la résa est intacte
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $status = $this->conn->executeQuery(
                'SELECT status FROM reservations WHERE id = :id',
                ['id' => $r['id']]
            )->fetchOne();
            self::assertSame('confirmed', $status);
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }
}
