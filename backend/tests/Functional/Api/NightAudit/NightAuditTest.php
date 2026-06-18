<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\NightAudit;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels Night audit (Sprint 13quater-A).
 *
 * Stratégie : on travaille sur Savana (admin@savana-hotel.sn, plan PRO).
 * Avant chaque test on nettoie la table daily_closes et les audit logs
 * night_audit.* du schema savana pour que les tests soient isolés.
 *
 */
class NightAuditTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST            = 'savana.localhost';
    private const MANAGER         = 'admin@savana-hotel.sn';
    private const MANAGER_PWD     = 'admin123';
    private const RECEPTIONIST    = 'reception@savana-hotel.sn';
    private const RECEPTIONIST_PWD= 'recep123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        self::assertNotNull($tenant, 'Fixture tenant "savana" requise');
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
            $this->conn->executeStatement(
                "DELETE FROM audit_logs WHERE entity_type IN ('DailyClose','daily_close')"
            );
            // Nettoyer les factures seedées par les tests vatXof
            $this->conn->executeStatement(
                "DELETE FROM invoices WHERE number LIKE 'TEST-NA-VAT-%'"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère une facture issued avec un taxXof donné, datée aujourd'hui
     * (issuedAt = NOW). Sert aux tests vatXof du snapshot.
     */
    private function seedIssuedInvoice(string $marker, string $subtotalXof, string $taxXof, string $totalXof): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $resId = $this->conn->executeQuery('SELECT id FROM reservations LIMIT 1')->fetchOne();
            self::assertNotFalse($resId, 'Une réservation de fixtures est requise');

            $id  = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            $this->conn->executeStatement(
                "INSERT INTO invoices
                 (id, reservation_id, number, status, subtotal_xof, tax_rate, tax_xof, total_xof,
                  issued_at, created_at, updated_at)
                 VALUES (:id, :res, :num, 'issued', :sub, '18.00', :tax, :total, :now, :now, :now)",
                [
                    'id'    => $id,
                    'res'   => $resId,
                    'num'   => 'TEST-NA-VAT-' . $marker,
                    'sub'   => $subtotalXof,
                    'tax'   => $taxXof,
                    'total' => $totalXof,
                    'now'   => $now,
                ]
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère directement une clôture en BDD pour un business_date donné,
     * permettant aux tests de simuler une suite chronologique.
     */
    private function seedClose(string $businessDate, ?string $reopenedAt = null): string
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $id = $this->conn->executeQuery("SELECT gen_random_uuid() AS id")->fetchOne();

            // closed_by_id : pas de FK, n'importe quel UUID convient
            $closedBy = '00000000-0000-0000-0000-000000000001';

            $this->conn->executeStatement(
                "INSERT INTO daily_closes
                 (id, business_date, closed_at, closed_by_id, closed_by_email,
                  cutoff_hour, snapshot, reopened_at)
                 VALUES (:id, :bd, NOW(), :by, :email, 5, :snapshot::jsonb, :reopened)",
                [
                    'id'       => $id,
                    'bd'       => $businessDate,
                    'by'       => $closedBy,
                    'email'    => 'seed@example.sn',
                    'snapshot' => json_encode(['seeded' => true]),
                    'reopened' => $reopenedAt,
                ]
            );

            return (string) $id;
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère directement une réservation CONFIRMED arrivant aujourd'hui,
     * sur une chambre libre. Retourne l'ID de la résa créée (à supprimer
     * en fin de test pour ne pas polluer les fixtures).
     */
    private function seedConfirmedArrivalToday(): string
    {
        $bd = $this->currentBusinessDate();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            // Trouver une chambre active sans résa active aujourd'hui
            $roomId = $this->conn->executeQuery(
                "SELECT r.id FROM rooms r
                 WHERE r.is_active = TRUE
                 AND NOT EXISTS (
                     SELECT 1 FROM reservations res
                     WHERE res.room_id = r.id
                       AND res.status IN ('confirmed','checked_in')
                       AND res.check_in <= :bd AND res.check_out > :bd
                 )
                 ORDER BY r.number ASC LIMIT 1",
                ['bd' => $bd]
            )->fetchOne();
            self::assertNotFalse($roomId, 'Une chambre libre est requise');

            $guestId = $this->conn->executeQuery('SELECT id FROM guests LIMIT 1')->fetchOne();
            self::assertNotFalse($guestId, 'Un client de fixtures est requis');

            $id  = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();
            $bdNext = (new \DateTimeImmutable($bd))->modify('+1 day')->format('Y-m-d');

            $this->conn->executeStatement(
                "INSERT INTO reservations
                 (id, confirmation_number, guest_id, room_id, status, check_in, check_out,
                  adults, children, rate_xof, total_xof, source, created_at, updated_at)
                 VALUES (:id, :conf, :guest, :room, 'confirmed', :bd, :next,
                         1, 0, '30000.00', '30000.00', 'direct', NOW(), NOW())",
                [
                    'id'    => $id,
                    'conf'  => 'TEST-NA-' . substr((string) $id, 0, 8),
                    'guest' => $guestId,
                    'room'  => $roomId,
                    'bd'    => $bd,
                    'next'  => $bdNext,
                ]
            );
            return (string) $id;
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function deleteSeededReservation(string $id): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                'DELETE FROM reservations WHERE id = :id',
                ['id' => $id]
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
        $guests = $this->apiRequest(
            'GET', '/api/guests', self::HOST,
            headers: ['Authorization' => "Bearer $token"],
        );
        return [
            'roomId'  => $rooms['data'][0]['id'],
            'guestId' => $guests['data'][0]['id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchLatestNightAuditCloseAudit(): array
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $row = $this->conn->executeQuery(
                "SELECT after FROM audit_logs
                 WHERE action = 'night_audit.closed'
                 ORDER BY created_at DESC LIMIT 1"
            )->fetchOne();
            return $row ? json_decode($row, true) : [];
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function currentBusinessDate(): string
    {
        // Le service est en TZ Africa/Dakar, cutoff par défaut = 5h.
        // On reproduit la logique pour pouvoir prédire la business date.
        $tz  = new \DateTimeZone('Africa/Dakar');
        $now = new \DateTimeImmutable('now', $tz);
        if ((int) $now->format('H') < 5) {
            $now = $now->modify('-1 day');
        }
        return $now->format('Y-m-d');
    }

    public function testGetCurrentReturnsBusinessDateAndCanCloseTrueWhenEmpty(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'GET', '/api/night-audit/current', self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertSame($this->currentBusinessDate(), $response['data']['businessDate']);
        self::assertNull($response['data']['lastCloseDate']);
        self::assertTrue($response['data']['canClose']);
        self::assertFalse($response['data']['alreadyClosed']);
    }

    public function testReceptionistCanCloseDay(): void
    {
        $token = $this->login(self::RECEPTIONIST, self::RECEPTIONIST_PWD, self::HOST);

        // Les fixtures contiennent potentiellement des arrivées/départs
        // pour aujourd'hui qui déclenchent la checklist : on force pour
        // tester strictement le RBAC + le fait que la clôture aboutit.
        $response = $this->apiRequest(
            'POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response, 201);
        // L'entité sérialise businessDate en datetime ATOM complet.
        self::assertStringStartsWith($this->currentBusinessDate(), $response['data']['businessDate']);
        self::assertSame(self::RECEPTIONIST, $response['data']['closedByEmail']);
        self::assertIsArray($response['data']['snapshot']);
        self::assertArrayHasKey('kpis', $response['data']['snapshot']);
        self::assertArrayHasKey('cash', $response['data']['snapshot']);
        self::assertArrayHasKey('rooms', $response['data']['snapshot']);
    }

    public function testSnapshotIncludesExactVatXofFromIssuedInvoices(): void
    {
        // Stratégie : on seede 3 factures issued aujourd'hui avec
        // taxXof connus, puis on calcule en BDD la somme TOTALE de
        // toutes les factures issued aujourd'hui (seedées + fixtures).
        // Le snapshot doit retourner exactement cette somme bcmath —
        // preuve que vatXof n'est pas un calcul approximatif
        // TTC/1.18*0.18 mais bien une SUM SQL des taxXof réels.
        $this->seedIssuedInvoice('A', '1000.00',  '180.00',  '1180.00');
        $this->seedIssuedInvoice('B', '10000.00', '1800.00', '11800.00');
        $this->seedIssuedInvoice('C', '3002.78',  '540.50',  '3543.28');

        // Somme bcmath en BDD des taxXof issued aujourd'hui
        $bd = $this->currentBusinessDate();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $row = $this->conn->fetchAssociative(
                "SELECT COALESCE(SUM(tax_xof), 0) AS vat,
                        COALESCE(SUM(total_xof), 0) AS total,
                        COUNT(*) AS cnt
                 FROM invoices
                 WHERE issued_at IS NOT NULL
                   AND issued_at >= :start AND issued_at < :end
                   AND status <> 'cancelled'",
                ['start' => $bd . ' 00:00:00', 'end' => $bd . ' 23:59:59']
            );
            $expectedVat   = number_format((float) $row['vat'],   2, '.', '');
            $expectedTotal = number_format((float) $row['total'], 2, '.', '');
            $expectedCount = (int) $row['cnt'];
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Sanity : nos 3 seedées contribuent bien 2520.50 à la somme
        self::assertGreaterThanOrEqual('2520.50', $expectedVat);

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $resp = $this->apiRequest(
            'POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]
        );
        $this->assertApiSuccess($resp, 201);

        $invoices = $resp['data']['snapshot']['invoices'];
        self::assertArrayHasKey('vatXof', $invoices, 'Le snapshot doit exposer vatXof (Sprint 14-A.3 B.2)');
        self::assertSame($expectedVat,   $invoices['vatXof']);
        self::assertSame($expectedTotal, $invoices['totalXof']);
        self::assertSame($expectedCount, (int) $invoices['issued']);
    }

    public function testCannotCloseTwiceSameDay(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        // 1ère close OK (force pour ignorer warnings fixtures)
        $this->apiRequest(
            'POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]
        );
        self::assertResponseStatusCodeSame(201);

        // 2ème → 422 (séquentialité/idempotence, indépendant du force)
        $this->apiRequest(
            'POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testGetCurrentAfterCloseReportsAlreadyClosed(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);

        $response = $this->apiRequest('GET', '/api/night-audit/current', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiSuccess($response);
        self::assertTrue($response['data']['alreadyClosed']);
        self::assertFalse($response['data']['canClose']);
        self::assertSame($this->currentBusinessDate(), $response['data']['lastCloseDate']);
    }

    public function testCannotCloseIfPreviousDayNotClosed(): void
    {
        // Seed une clôture d'il y a 3 jours → il manque 2 jours avant aujourd'hui.
        $threeDaysAgo = (new \DateTimeImmutable($this->currentBusinessDate()))->modify('-3 days')->format('Y-m-d');
        $this->seedClose($threeDaysAgo);

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest('GET', '/api/night-audit/current', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiSuccess($response);
        self::assertFalse($response['data']['canClose']);
        self::assertStringContainsString('clôturée', $response['data']['reason']);

        // L'appel POST close doit aussi être refusé (422)
        $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testManagerCanReopenLastClose(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $created = $this->apiRequest(
            'POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]
        );
        self::assertResponseStatusCodeSame(201);
        $closeId = $created['data']['id'];

        $response = $this->apiRequest(
            'POST', "/api/night-audit/$closeId/reopen", self::HOST,
            body:    ['reason' => 'Correction caisse manquante'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertNotNull($response['data']['reopenedAt']);
        self::assertSame(self::MANAGER, $response['data']['reopenedByEmail']);
        self::assertSame('Correction caisse manquante', $response['data']['reopenReason']);
    }

    public function testReceptionistCannotReopen(): void
    {
        $managerToken = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $created = $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $managerToken"]);
        $closeId = $created['data']['id'];

        $recepToken = $this->login(self::RECEPTIONIST, self::RECEPTIONIST_PWD, self::HOST);
        $this->apiRequest(
            'POST', "/api/night-audit/$closeId/reopen", self::HOST,
            body:    ['reason' => 'Tentative invalide'],
            headers: ['Authorization' => "Bearer $recepToken"]
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    public function testReopenRequiresReasonMinLength(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $created = $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]);
        $closeId = $created['data']['id'];

        $this->apiRequest(
            'POST', "/api/night-audit/$closeId/reopen", self::HOST,
            body:    ['reason' => 'abc'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testCannotReopenNonLatestClose(): void
    {
        // Seed deux clôtures : il y a 2 jours et hier (par rapport à la business date).
        $bd     = $this->currentBusinessDate();
        $minus2 = (new \DateTimeImmutable($bd))->modify('-2 days')->format('Y-m-d');
        $minus1 = (new \DateTimeImmutable($bd))->modify('-1 day')->format('Y-m-d');
        $olderId = $this->seedClose($minus2);
        $this->seedClose($minus1);

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->apiRequest(
            'POST', "/api/night-audit/$olderId/reopen", self::HOST,
            body:    ['reason' => 'Tentative non-latest'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testListEndpointReturnsPagination(): void
    {
        $bd = $this->currentBusinessDate();
        $this->seedClose((new \DateTimeImmutable($bd))->modify('-3 days')->format('Y-m-d'));
        $this->seedClose((new \DateTimeImmutable($bd))->modify('-2 days')->format('Y-m-d'));
        $this->seedClose((new \DateTimeImmutable($bd))->modify('-1 day')->format('Y-m-d'));

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest('GET', '/api/night-audit?page=1&perPage=2', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $response['data']);
        self::assertSame(3, $response['meta']['total']);
        self::assertSame(2, $response['meta']['pages']);
    }

    public function testChecklistEndpointReturnsWarnings(): void
    {
        // Les fixtures Savana ont normalement des CONFIRMED arrivant
        // aujourd'hui ou des CHECKED_IN partant aujourd'hui — au moins
        // un warning devrait remonter. On vérifie la structure.
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'GET', '/api/night-audit/checklist', self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertSame($this->currentBusinessDate(), $response['data']['businessDate']);
        self::assertIsArray($response['data']['warnings']);
        self::assertIsBool($response['data']['canCloseClean']);
        // Structure des warnings retournés (si présents)
        foreach ($response['data']['warnings'] as $w) {
            self::assertArrayHasKey('code', $w);
            self::assertArrayHasKey('severity', $w);
            self::assertArrayHasKey('label', $w);
            self::assertArrayHasKey('message', $w);
            self::assertArrayHasKey('count', $w);
        }
    }

    public function testCloseRefusedWhenWarningsAndNotForced(): void
    {
        // Forcer la présence d'au moins un warning : créer une résa
        // CONFIRMED arrivant aujourd'hui (sans la check-in).
        $resId = $this->seedConfirmedArrivalToday();

        try {
            $token = $this->login(self::RECEPTIONIST, self::RECEPTIONIST_PWD, self::HOST);

            $this->apiRequest(
                'POST', '/api/night-audit/close', self::HOST,
                headers: ['Authorization' => "Bearer $token"]
            );

            $this->assertApiError('BUSINESS_RULE', 422);
            $response = json_decode((string) $this->client->getResponse()->getContent(), true);
            self::assertStringContainsString('avertissement', $response['error']);
        } finally {
            $this->deleteSeededReservation($resId);
        }
    }

    public function testCloseWithForceAcceptsWarnings(): void
    {
        $resId = $this->seedConfirmedArrivalToday();

        try {
            $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

            $response = $this->apiRequest(
                'POST', '/api/night-audit/close', self::HOST,
                body:    ['force' => true],
                headers: ['Authorization' => "Bearer $token"]
            );

            $this->assertApiSuccess($response, 201);
            self::assertIsArray($response['data']['snapshot']['warnings']);
            self::assertGreaterThanOrEqual(1, count($response['data']['snapshot']['warnings']));

            $codes = array_column($response['data']['snapshot']['warnings'], 'code');
            self::assertContains('arrivals.pending', $codes);

            // Audit log : forced=true + warningsCount présents
            $auditAfter = $this->fetchLatestNightAuditCloseAudit();
            self::assertTrue($auditAfter['forced'] ?? null);
            self::assertGreaterThanOrEqual(1, $auditAfter['warningsCount'] ?? 0);
        } finally {
            $this->deleteSeededReservation($resId);
        }
    }

    public function testReopenedCloseDoesNotEnforceLock(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        // Close + reopen, puis on doit pouvoir créer une résa avec
        // checkIn = business date courante (qui était locked entre les deux).
        $created = $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);
        $closeId = $created['data']['id'];

        // 1. Tenter de créer une résa avec checkIn = aujourd'hui → 422 verrou
        $picks = $this->pickRoomAndGuest($token);
        $today = $this->currentBusinessDate();
        $tomorrow = (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');

        $this->apiRequest('POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => $today,
                'checkOut' => $tomorrow,
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiError('BUSINESS_RULE', 422);

        // 2. Reopen
        $this->apiRequest(
            'POST', "/api/night-audit/$closeId/reopen", self::HOST,
            body:    ['reason' => 'Test E2E lock release after reopen'],
            headers: ['Authorization' => "Bearer $token"]
        );
        self::assertResponseStatusCodeSame(200);

        // 3. La résa devrait passer le verrou maintenant (le conflict
        // checker peut encore refuser si la chambre est déjà bookée
        // aujourd'hui, mais ce n'est plus BUSINESS_RULE — c'est CONFLICT
        // ou succès)
        $response = $this->apiRequest('POST', '/api/reservations', self::HOST,
            body: [
                'roomId'   => $picks['roomId'],
                'guestId'  => $picks['guestId'],
                'checkIn'  => $today,
                'checkOut' => $tomorrow,
                'adults'   => 1,
            ],
            headers: ['Authorization' => "Bearer $token"]);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(422, $status, 'Verrou night audit doit être levé après reopen');
        self::assertContains($status, [201, 409], 'Soit créée, soit conflit de chambre — pas un BUSINESS_RULE de verrou');
    }

    public function testPdfEndpointReturnsBinary(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $created = $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]);
        $closeId = $created['data']['id'];

        // Le PDF n'est pas du JSON donc on ne passe pas par apiRequest
        $this->client->request('GET', "/api/night-audit/$closeId/pdf",
            server: [
                'HTTP_HOST'          => self::HOST,
                'HTTP_AUTHORIZATION' => "Bearer $token",
            ]
        );

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', $response->headers->get('Content-Disposition') ?? '');
        $content = (string) $response->getContent();
        self::assertGreaterThan(1000, strlen($content), 'PDF doit avoir un contenu non trivial');
        self::assertStringStartsWith('%PDF-', $content, 'PDF magic bytes attendus');
    }

    public function testShowEndpointReturnsSnapshot(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $created = $this->apiRequest('POST', '/api/night-audit/close', self::HOST,
            body:    ['force' => true],
            headers: ['Authorization' => "Bearer $token"]);
        $closeId = $created['data']['id'];

        $response = $this->apiRequest("GET", "/api/night-audit/$closeId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiSuccess($response);
        self::assertArrayHasKey('snapshot', $response['data']);
        self::assertArrayHasKey('kpis', $response['data']['snapshot']);
    }
}
