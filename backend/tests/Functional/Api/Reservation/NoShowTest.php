<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Reservation;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — POST /api/reservations/{id}/no-show
 *
 * Stratégie : on insère directement en BDD une résa CONFIRMED arrivant
 * aujourd'hui (sans passer par l'API qui pourrait être bloquée par un
 * conflit fixture), on appelle l'endpoint, on vérifie l'état + la
 * facture éventuelle, puis on rollback en tearDown.
 *
 */
class NoShowTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST          = 'savana.localhost';
    private const MANAGER       = 'admin@savana-hotel.sn';
    private const MANAGER_PWD   = 'admin123';
    private const RECEPTIONIST  = 'reception@savana-hotel.sn';
    private const RECEPT_PWD    = 'recep123';
    private const HOUSEKEEPER   = 'menage@savana-hotel.sn';
    private const HK_PWD        = 'menage123';

    private const CONFIRMATION_PREFIX = 'TEST-NOSHOW-';

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
        } finally {
            parent::tearDown();
        }
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            // Lignes de facture des tests no-show créées par cascade INSERT
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
                 WHERE action = 'reservation.no_show'
                    OR action = 'invoice.no_show_fee_created'"
            );
            $this->conn->executeStatement('DELETE FROM daily_closes');
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère une résa CONFIRMED arrivant aujourd'hui, sur une chambre
     * libre. Retourne son UUID.
     */
    private function seedConfirmedArrivingToday(string $marker = 'A'): string
    {
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar')))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('tomorrow', new \DateTimeZone('Africa/Dakar')))->format('Y-m-d');

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $roomId = $this->conn->executeQuery(
                "SELECT r.id FROM rooms r
                 WHERE r.is_active = TRUE
                 AND NOT EXISTS (
                     SELECT 1 FROM reservations res
                     WHERE res.room_id = r.id
                       AND res.status IN ('confirmed','checked_in')
                       AND res.check_in <= :today AND res.check_out > :today
                 )
                 ORDER BY r.number ASC LIMIT 1",
                ['today' => $today]
            )->fetchOne();
            self::assertNotFalse($roomId, 'Chambre libre attendue.');

            $guestId = $this->conn->executeQuery('SELECT id FROM guests LIMIT 1')->fetchOne();
            $id = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();

            $this->conn->executeStatement(
                "INSERT INTO reservations
                 (id, confirmation_number, guest_id, room_id, status,
                  check_in, check_out, adults, children, rate_xof, total_xof,
                  source, created_at, updated_at)
                 VALUES (:id, :conf, :guest, :room, 'confirmed', :today, :tomorrow,
                         1, 0, '35000.00', '35000.00', 'direct', NOW(), NOW())",
                [
                    'id'       => $id,
                    'conf'     => self::CONFIRMATION_PREFIX . $marker,
                    'guest'    => $guestId,
                    'room'     => $roomId,
                    'today'    => $today,
                    'tomorrow' => $tomorrow,
                ]
            );
            return (string) $id;
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testReceptionistCanMarkNoShow(): void
    {
        $id = $this->seedConfirmedArrivingToday('R1');
        $token = $this->login(self::RECEPTIONIST, self::RECEPT_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('no_show', $resp['data']['reservation']['status']);
    }

    public function testNoShowWithFirstNightPolicyCreatesInvoice(): void
    {
        $id = $this->seedConfirmedArrivingToday('R2');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('first_night', $resp['data']['policy']);
        self::assertSame('35000.00', $resp['data']['feeXof']);
        self::assertNotNull($resp['data']['invoice']);
        self::assertSame('issued', $resp['data']['invoice']['status']);
        self::assertSame('35000.00', $resp['data']['invoice']['totalXof']);
    }

    public function testNoShowWithNoneOverrideCreatesNoInvoice(): void
    {
        $id = $this->seedConfirmedArrivingToday('R3');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            body:    ['policy' => 'none'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('none', $resp['data']['policy']);
        self::assertSame('0.00', $resp['data']['feeXof']);
        self::assertNull($resp['data']['invoice']);
    }

    public function testNoShowWithFullOverrideUsesTotalXof(): void
    {
        $id = $this->seedConfirmedArrivingToday('R4');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            body:    ['policy' => 'full'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('full', $resp['data']['policy']);
        self::assertSame('35000.00', $resp['data']['feeXof']); // total = rate (1 nuit)
        self::assertNotNull($resp['data']['invoice']);
    }

    public function testNoShowOnCheckedInReservationRefused(): void
    {
        $id = $this->seedConfirmedArrivingToday('R5');

        // Passer en CHECKED_IN directement
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "UPDATE reservations SET status='checked_in' WHERE id = :id",
                ['id' => $id]
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testHousekeeperCannotMarkNoShow(): void
    {
        $id = $this->seedConfirmedArrivingToday('R6');
        $token = $this->login(self::HOUSEKEEPER, self::HK_PWD, self::HOST);

        $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Insère une résa CONFIRMED arrivant dans le futur (+5 jours),
     * sur une chambre libre. Sert à prouver que le serveur refuse un
     * no-show futur — même si le frontend ne propose pas le bouton.
     */
    private function seedConfirmedArrivingInFuture(string $marker = 'F'): string
    {
        $tz       = new \DateTimeZone('Africa/Dakar');
        $checkIn  = (new \DateTimeImmutable('+5 days', $tz))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable('+6 days', $tz))->format('Y-m-d');

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $roomId = $this->conn->executeQuery(
                "SELECT r.id FROM rooms r
                 WHERE r.is_active = TRUE
                 AND NOT EXISTS (
                     SELECT 1 FROM reservations res
                     WHERE res.room_id = r.id
                       AND res.status IN ('confirmed','checked_in')
                       AND res.check_in < :checkOut AND res.check_out > :checkIn
                 )
                 ORDER BY r.number ASC LIMIT 1",
                ['checkIn' => $checkIn, 'checkOut' => $checkOut]
            )->fetchOne();
            self::assertNotFalse($roomId, 'Chambre libre attendue.');

            $guestId = $this->conn->executeQuery('SELECT id FROM guests LIMIT 1')->fetchOne();
            $id      = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();

            $this->conn->executeStatement(
                "INSERT INTO reservations
                 (id, confirmation_number, guest_id, room_id, status,
                  check_in, check_out, adults, children, rate_xof, total_xof,
                  source, created_at, updated_at)
                 VALUES (:id, :conf, :guest, :room, 'confirmed', :checkIn, :checkOut,
                         1, 0, '35000.00', '35000.00', 'direct', NOW(), NOW())",
                [
                    'id'       => $id,
                    'conf'     => self::CONFIRMATION_PREFIX . $marker,
                    'guest'    => $guestId,
                    'room'     => $roomId,
                    'checkIn'  => $checkIn,
                    'checkOut' => $checkOut,
                ]
            );
            return (string) $id;
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testNoShowOnFutureCheckInRefused(): void
    {
        $id    = $this->seedConfirmedArrivingInFuture('F1');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString('futur', strtolower($resp['error'] ?? ''));
    }

    public function testNoShowOnPastClosedDayRefused(): void
    {
        $id = $this->seedConfirmedArrivingToday('R7');

        // Seed une clôture qui couvre aujourd'hui : tout no-show
        // sur une résa arrivant aujourd'hui est verrouillé.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar')))->format('Y-m-d');
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "INSERT INTO daily_closes
                 (id, business_date, closed_at, closed_by_id, closed_by_email,
                  cutoff_hour, snapshot)
                 VALUES (gen_random_uuid(), :bd, NOW(),
                         '00000000-0000-0000-0000-000000000001',
                         'seed@example.sn', 5, '{}'::jsonb)",
                ['bd' => $today]
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $this->apiRequest(
            'POST', "/api/reservations/$id/no-show", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }
}
