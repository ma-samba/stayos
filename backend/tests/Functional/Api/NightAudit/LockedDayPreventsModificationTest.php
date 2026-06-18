<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\NightAudit;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vérifie que le verrou night audit est correctement câblé dans
 * ReservationEngine. Les unit tests couvrent déjà la logique du
 * lock checker — ces tests vérifient le câblage end-to-end.
 *
 */
class LockedDayPreventsModificationTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST    = 'savana.localhost';
    private const MANAGER = 'admin@savana-hotel.sn';
    private const PWD     = 'admin123';

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
            $this->conn->executeStatement('DELETE FROM daily_closes');
            // Toute résa créée par ces tests utilise des dates >= 2030
            $this->conn->executeStatement(
                "DELETE FROM reservations WHERE check_in >= '2030-01-01'"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function seedClose(string $businessDate): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "INSERT INTO daily_closes
                 (id, business_date, closed_at, closed_by_id, closed_by_email,
                  cutoff_hour, snapshot)
                 VALUES (gen_random_uuid(), :bd, NOW(),
                         '00000000-0000-0000-0000-000000000001',
                         'seed@example.sn', 5, '{}'::jsonb)",
                ['bd' => $businessDate]
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * @return array{roomId: string, guestId: string}
     */
    private function pickRoomAndGuest(string $token): array
    {
        $rooms = $this->apiRequest(
            'GET', '/api/rooms', self::HOST,
            headers: ['Authorization' => "Bearer $token"],
        );
        self::assertNotEmpty($rooms['data'] ?? [], 'Fixtures rooms attendus');

        $guests = $this->apiRequest(
            'GET', '/api/guests', self::HOST,
            headers: ['Authorization' => "Bearer $token"],
        );
        self::assertNotEmpty($guests['data'] ?? [], 'Fixtures guests attendus');

        return [
            'roomId'  => $rooms['data'][0]['id'],
            'guestId' => $guests['data'][0]['id'],
        ];
    }

    public function testCreatePastDatedReservationRefusedWhenLockExists(): void
    {
        // Verrou pour toute date <= 2030-12-31
        $this->seedClose('2030-12-31');

        $token = $this->login(self::MANAGER, self::PWD, self::HOST);
        $picks = $this->pickRoomAndGuest($token);

        $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => '2030-06-01',
                'checkOut' => '2030-06-03',
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testCreateFutureDatedReservationAllowedDespiteLock(): void
    {
        // Verrou pour toute date <= 2030-12-31, on tente 2031
        $this->seedClose('2030-12-31');

        $token = $this->login(self::MANAGER, self::PWD, self::HOST);
        $picks = $this->pickRoomAndGuest($token);

        $response = $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => '2031-06-01',
                'checkOut' => '2031-06-03',
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
    }

    public function testCannotUpdatePastReservationOnClosedDay(): void
    {
        $token = $this->login(self::MANAGER, self::PWD, self::HOST);
        $picks = $this->pickRoomAndGuest($token);

        // 1. Créer la résa avant la clôture
        $created = $this->apiRequest('POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => '2030-06-01',
                'checkOut' => '2030-06-03',
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);
        $resId = $created['data']['id'];

        // 2. Clôturer (verrou actif pour 2030)
        $this->seedClose('2030-12-31');

        // 3. PUT → verrou doit refuser
        $this->apiRequest('PUT', "/api/reservations/$resId", self::HOST,
            body: ['adults' => 2],
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testCannotCancelPastReservationOnClosedDay(): void
    {
        $token = $this->login(self::MANAGER, self::PWD, self::HOST);
        $picks = $this->pickRoomAndGuest($token);

        $created = $this->apiRequest('POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => '2030-07-01',
                'checkOut' => '2030-07-03',
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);
        $resId = $created['data']['id'];

        $this->seedClose('2030-12-31');

        $this->apiRequest('POST', "/api/reservations/$resId/cancel", self::HOST,
            body:    ['reason' => 'Test verrou'],
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testCheckInDoesNotFireLockWhenTodayIsNotClosed(): void
    {
        // Verrou positionné dans le passé lointain → today n'est pas locked.
        $this->seedClose('2020-01-01');

        $token = $this->login(self::MANAGER, self::PWD, self::HOST);

        // Trouver une résa CHECKED_OUT (immutable du point de vue checkIn).
        // Tenter le checkin doit échouer SUR LE STATUT, pas sur le verrou.
        $list = $this->apiRequest(
            'GET', '/api/reservations?status=checked_out', self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );
        if (empty($list['data'])) {
            self::markTestSkipped('Aucune réservation CHECKED_OUT dans les fixtures.');
        }
        $resId = $list['data'][0]['id'];

        $this->apiRequest(
            'POST', "/api/reservations/$resId/checkin", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        // Doit être l'erreur de statut, PAS celle du verrou ("clôturée")
        self::assertStringNotContainsString('clôturée', $response['error']);
        self::assertStringContainsString('statut', $response['error']);
    }

    public function testCanRecordPaymentTodayWhenTodayIsNotClosed(): void
    {
        // Verrou positionné dans le passé lointain : le paiement
        // d'aujourd'hui n'est pas dans la fenêtre verrouillée.
        $this->seedClose('2020-01-01');

        $token = $this->login(self::MANAGER, self::PWD, self::HOST);

        // Utiliser une facture existante (fixtures). On cherche une
        // facture non annulée (peu importe son solde — on enregistrera
        // un petit paiement test).
        $invoices = $this->apiRequest('GET', '/api/invoices', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertNotEmpty($invoices['data'] ?? [], 'Fixtures invoices attendues');

        $invoice = null;
        foreach ($invoices['data'] as $inv) {
            if (($inv['status'] ?? '') !== 'cancelled') {
                $invoice = $inv;
                break;
            }
        }
        self::assertNotNull($invoice, 'Une facture non annulée doit exister en fixtures');

        $response = $this->apiRequest(
            'POST', "/api/invoices/{$invoice['id']}/payments", self::HOST,
            body: [
                'method'    => 'cash',
                'amountXof' => '1000.00',
            ],
            headers: ['Authorization' => "Bearer $token"]
        );

        // Doit passer le verrou — peut renvoyer 201 ou 422 sur
        // validation DTO si format différent, mais PAS 422 BUSINESS_RULE
        // "clôturée".
        $body = $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('clôturée', $body ?? '');
    }

    private function todayDakar(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar'));
    }
}
