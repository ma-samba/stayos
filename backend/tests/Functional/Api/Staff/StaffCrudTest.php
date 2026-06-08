<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Staff;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — CRUD staff (Sprint 13bis).
 *
 * Utilise Savana (plan PRO, maxUsers=20, 3 staff) pour avoir de la
 * marge sur les créations/réactivations.
 *
 * @group integration
 */
class StaffCrudTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    private const HOST     = 'savana.localhost';
    private const MANAGER  = 'admin@savana-hotel.sn';
    private const PASSWORD = 'admin123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->cleanupTestRows();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupTestRows();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupTestRows(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        if ($tenant === null) {
            return;
        }
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            // Purger l'audit log de test (sinon les comptages cumulent
            // entre runs et faussent les assertions).
            $this->conn->executeStatement(
                "DELETE FROM audit_logs
                 WHERE entity_type IN ('staff_user','staff_invitation')"
            );
            $this->conn->executeStatement(
                "DELETE FROM staff_users WHERE email LIKE 'test-crud-%@example.sn'"
            );
            // Réactive le staff de fixtures au cas où un test l'aurait désactivé
            $this->conn->executeStatement(
                "UPDATE staff_users SET active = TRUE WHERE email IN
                 ('reception@savana-hotel.sn','menage@savana-hotel.sn')"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Renvoie les audit logs (entityType, entityId) ordonnés DESC.
     *
     * @return array<int, array{action: string, before: ?array, after: ?array, staffUserEmail: ?string}>
     */
    private function fetchAuditLogs(string $entityType, string $entityId): array
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT action, before, after, staff_user_email
                 FROM audit_logs
                 WHERE entity_type = ? AND entity_id = ?
                 ORDER BY created_at DESC, id DESC',
                [$entityType, $entityId],
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        return array_map(fn (array $r) => [
            'action'          => $r['action'],
            'before'          => $r['before']  !== null ? json_decode($r['before'],  true) : null,
            'after'           => $r['after']   !== null ? json_decode($r['after'],   true) : null,
            'staffUserEmail'  => $r['staff_user_email'] ?? null,
        ], $rows);
    }

    private function loginManager(): string
    {
        return $this->login(self::MANAGER, self::PASSWORD, self::HOST);
    }

    public function testManagerCanCreateStaff(): void
    {
        $token = $this->loginManager();

        $response = $this->apiRequest(
            'POST',
            '/api/staff',
            self::HOST,
            body: [
                'email'     => 'test-crud-new@example.sn',
                'firstName' => 'New',
                'lastName'  => 'Hire',
                'role'      => 'ACCOUNTANT',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
        self::assertSame('test-crud-new@example.sn', $response['data']['email']);
        self::assertSame('ACCOUNTANT', $response['data']['role']);
        self::assertNotEmpty($response['data']['tempPassword']);
    }

    public function testReceptionistCannotCreateStaff(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);

        $this->apiRequest(
            'POST',
            '/api/staff',
            self::HOST,
            body: [
                'email'     => 'test-crud-x@example.sn',
                'firstName' => 'X',
                'lastName'  => 'Y',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReceptionistCanListButNotMutate(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);

        $response = $this->apiRequest('GET', '/api/staff', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);

        // PUT/DELETE refusés
        $randomId = '00000000-0000-0000-0000-000000000000';
        $this->apiRequest('PUT', "/api/staff/$randomId", self::HOST, body: ['firstName' => 'X'],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testManagerCannotDeactivateThemself(): void
    {
        $token = $this->loginManager();

        $manager = $this->em->getRepository(StaffUser::class);
        $tenant  = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema  = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $self = $manager->findOneBy(['email' => self::MANAGER]);
            $selfId = (string) $self->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $this->apiRequest('DELETE', "/api/staff/$selfId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testSoftDeleteSetsActiveFalseAndBlocksLogin(): void
    {
        $token = $this->loginManager();

        // Récupérer l'id du réceptionniste
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $reception = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'reception@savana-hotel.sn']);
            $receptionId = (string) $reception->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Désactiver
        $response = $this->apiRequest('DELETE', "/api/staff/$receptionId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);
        self::assertFalse($response['data']['active']);

        // Le user existe toujours en BDD
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $reception = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'reception@savana-hotel.sn']);
            self::assertNotNull($reception);
            self::assertFalse($reception->isActive());
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Login refusé pour user inactif
        $this->apiRequest('POST', '/api/auth/login', self::HOST,
            body: ['email' => 'reception@savana-hotel.sn', 'password' => 'recep123']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testReactivateRequiresAvailableSlot(): void
    {
        $token = $this->loginManager();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $reception = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'reception@savana-hotel.sn']);
            $reception->setActive(false);
            $this->em->flush();
            $receptionId = (string) $reception->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Plan PRO maxUsers=20 → place dispo → réactivation OK
        $response = $this->apiRequest('POST', "/api/staff/$receptionId/reactivate", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);
        self::assertTrue($response['data']['active']);
    }

    public function testUpdateStaffChangesRole(): void
    {
        $token = $this->loginManager();

        // Créer un staff de test
        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-update@example.sn',
                'firstName' => 'Up',
                'lastName'  => 'Date',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);
        $id = $created['data']['id'];

        // Update le rôle
        $response = $this->apiRequest('PUT', "/api/staff/$id", self::HOST,
            body: ['role' => 'RECEPTIONIST'],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);
        self::assertSame('RECEPTIONIST', $response['data']['role']);
    }

    public function testResetPasswordReturnsTempPassword(): void
    {
        $token = $this->loginManager();

        // Créer un staff
        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-reset@example.sn',
                'firstName' => 'Re',
                'lastName'  => 'Set',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $id = $created['data']['id'];

        $response = $this->apiRequest('POST', "/api/staff/$id/reset-password", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);
        self::assertNotEmpty($response['data']['tempPassword']);

        // Login OK avec le nouveau password
        $newToken = $this->login('test-crud-reset@example.sn',
            $response['data']['tempPassword'], self::HOST);
        self::assertNotEmpty($newToken);
    }

    // ── Audit trail ─────────────────────────────────────────────

    public function testCreateLogsAudit(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-audit-c@example.sn',
                'firstName' => 'Audit',
                'lastName'  => 'Create',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);

        $logs = $this->fetchAuditLogs('staff_user', $created['data']['id']);
        self::assertCount(1, $logs);
        self::assertSame('staff_user.created', $logs[0]['action']);
        self::assertSame(self::MANAGER, $logs[0]['staffUserEmail']);
        self::assertSame('test-crud-audit-c@example.sn', $logs[0]['after']['email']);
        self::assertNull($logs[0]['before']);
    }

    public function testUpdateLogsAuditWithBeforeAfter(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-audit-u@example.sn',
                'firstName' => 'Up',
                'lastName'  => 'Date',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $id = $created['data']['id'];

        $this->apiRequest('PUT', "/api/staff/$id", self::HOST,
            body: ['role' => 'RECEPTIONIST'],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(200);

        $logs = $this->fetchAuditLogs('staff_user', $id);
        // [0] = update, [1] = create (DESC)
        self::assertSame('staff_user.updated', $logs[0]['action']);
        self::assertSame('HOUSEKEEPER',  $logs[0]['before']['role']);
        self::assertSame('RECEPTIONIST', $logs[0]['after']['role']);
    }

    public function testUpdateNoChangesSkipsAudit(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-audit-noop@example.sn',
                'firstName' => 'Noop',
                'lastName'  => 'Test',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $id = $created['data']['id'];

        // PUT avec les mêmes valeurs → pas de nouvel audit
        $this->apiRequest('PUT', "/api/staff/$id", self::HOST,
            body: ['firstName' => 'Noop', 'lastName' => 'Test', 'role' => 'HOUSEKEEPER'],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(200);

        $logs = $this->fetchAuditLogs('staff_user', $id);
        self::assertCount(1, $logs, 'Seul le created doit être loggué.');
        self::assertSame('staff_user.created', $logs[0]['action']);
    }

    public function testDeactivateLogsAudit(): void
    {
        $token = $this->loginManager();

        // Désactiver le réceptionniste
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $reception = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'reception@savana-hotel.sn']);
            $receptionId = (string) $reception->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $this->apiRequest('DELETE', "/api/staff/$receptionId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(200);

        $logs = $this->fetchAuditLogs('staff_user', $receptionId);
        self::assertSame('staff_user.deactivated', $logs[0]['action']);
        self::assertTrue($logs[0]['before']['active']);
        self::assertFalse($logs[0]['after']['active']);
    }

    public function testResetPasswordLogsAuditWithoutLeakingSecrets(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-audit-r@example.sn',
                'firstName' => 'Re',
                'lastName'  => 'Set',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $id = $created['data']['id'];

        $this->apiRequest('POST', "/api/staff/$id/reset-password", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(200);

        $logs = $this->fetchAuditLogs('staff_user', $id);
        self::assertSame('staff_user.password_reset', $logs[0]['action']);
        // ⚠️ before/after doivent être null pour ne PAS fuiter le password
        self::assertNull($logs[0]['before']);
        self::assertNull($logs[0]['after']);
    }

    public function testAuditEndpointReturnsHistory(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/staff', self::HOST,
            body: [
                'email'     => 'test-crud-audit-list@example.sn',
                'firstName' => 'List',
                'lastName'  => 'Audit',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $id = $created['data']['id'];

        // Une seconde action pour avoir au moins 2 entrées
        $this->apiRequest('PUT', "/api/staff/$id", self::HOST,
            body: ['role' => 'RECEPTIONIST'],
            headers: ['Authorization' => "Bearer $token"]);

        $response = $this->apiRequest('GET', "/api/staff/$id/audit", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);

        $actions = array_column($response['data'], 'action');
        self::assertContains('staff_user.created', $actions);
        self::assertContains('staff_user.updated', $actions);
        // Tri DESC → updated d'abord
        self::assertSame('staff_user.updated', $response['data'][0]['action']);
    }

    public function testAuditEndpointRequiresManager(): void
    {
        // RECEPTIONIST sur Savana
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);
        $randomId = '00000000-0000-0000-0000-000000000000';

        $this->apiRequest('GET', "/api/staff/$randomId/audit", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(403);
    }

    // ── Activity (actions faites PAR le staff) ──────────────────

    /**
     * Insère un audit_log directement dans le schema tenant.
     * Évite la lourdeur de monter une vraie chaîne réservation
     * (fixtures de chambres + dates libres) pour valider
     * `findByStaffUser` qui n'a besoin que de l'email.
     */
    private function insertAuditLog(
        string $schema,
        string $action,
        string $entityType,
        string $entityId,
        string $staffUserEmail,
        string $staffUserRole = 'MANAGER',
        ?array $after = null,
    ): void {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                "INSERT INTO audit_logs
                   (id, action, entity_type, entity_id, staff_user_email,
                    staff_user_role, before, after, ip_address, user_agent, created_at)
                 VALUES (gen_random_uuid(), ?, ?, ?, ?, ?, NULL, ?, NULL, NULL, NOW())",
                [
                    $action,
                    $entityType,
                    $entityId,
                    $staffUserEmail,
                    $staffUserRole,
                    $after !== null ? json_encode($after) : null,
                ],
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testActivityEndpointReturnsActionsDoneByStaff(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $managerId = (string) $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => self::MANAGER])->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $this->insertAuditLog(
            schema:         $schema,
            action:         'reservation.created',
            entityType:     'Reservation',
            entityId:       'fake-res-001',
            staffUserEmail: self::MANAGER,
            after:          ['room' => '312'],
        );
        // garantir un createdAt distinct pour le tri DESC
        usleep(1_100_000);
        $this->insertAuditLog(
            schema:         $schema,
            action:         'reservation.checkin',
            entityType:     'Reservation',
            entityId:       'fake-res-001',
            staffUserEmail: self::MANAGER,
            after:          ['room' => '312'],
        );

        $token    = $this->loginManager();
        $response = $this->apiRequest('GET', "/api/staff/$managerId/activity", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);

        $actions = array_column($response['data'], 'action');
        self::assertContains('reservation.created', $actions);
        self::assertContains('reservation.checkin', $actions);

        // Tri DESC → checkin (le plus récent) avant created
        $first = $response['data'][0];
        self::assertSame('reservation.checkin', $first['action']);
        self::assertSame('Reservation', $first['entityType']);
        self::assertSame('312', $first['after']['room']);
    }

    public function testActivityEndpointDoesNotReturnOtherStaffActions(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $managerId = (string) $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => self::MANAGER])->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Action de la RÉCEPTION (pas du manager)
        $this->insertAuditLog(
            schema:         $schema,
            action:         'reservation.created',
            entityType:     'Reservation',
            entityId:       'fake-res-other',
            staffUserEmail: 'reception@savana-hotel.sn',
            staffUserRole:  'RECEPTIONIST',
            after:          ['room' => '101'],
        );

        $token    = $this->loginManager();
        $response = $this->apiRequest('GET', "/api/staff/$managerId/activity", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($response);

        // Le journal du manager ne doit PAS contenir l'action de la réception
        $entityIds = array_column($response['data'], 'entityId');
        self::assertNotContains('fake-res-other', $entityIds);
    }

    public function testActivityEndpointRequiresManager(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);
        $randomId = '00000000-0000-0000-0000-000000000000';

        $this->apiRequest('GET', "/api/staff/$randomId/activity", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(403);
    }
}
