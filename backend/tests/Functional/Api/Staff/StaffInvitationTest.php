<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Staff;

use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Domain\Enum\InvitationStatus;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — flux d'invitation employé (Sprint 13bis).
 *
 * Utilise villa-collines.localhost (plan STARTER, maxUsers=5,
 * 1 manager existant → 4 places restantes) pour pouvoir tester la
 * limite plan. setUp + tearDown nettoient invitations + staff
 * créés pour ne pas polluer les fixtures.
 *
 */
class StaffInvitationTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    private const TENANT_SLUG = 'villa-collines';
    private const HOST        = 'villa-collines.localhost';
    private const MANAGER     = 'admin@villa-collines.sn';
    private const PASSWORD    = 'admin123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $this->cleanupVillaTestRows();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupVillaTestRows();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupVillaTestRows(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => self::TENANT_SLUG]);
        if ($tenant === null) {
            return;
        }
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                "DELETE FROM audit_logs
                 WHERE entity_type IN ('StaffUser','StaffInvitation','staff_user','staff_invitation')"
            );
            $this->conn->executeStatement(
                "DELETE FROM staff_invitations WHERE email LIKE 'test-%@example.sn'"
            );
            $this->conn->executeStatement(
                "DELETE FROM staff_users WHERE email LIKE 'test-%@example.sn'"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * @return array<int, array{action: string, staffUserEmail: ?string, before: ?array, after: ?array}>
     */
    private function fetchAuditLogs(string $entityType, string $entityId): array
    {
        $tenant = $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => self::TENANT_SLUG]);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT action, staff_user_email, before, after
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
            'action'         => $r['action'],
            'staffUserEmail' => $r['staff_user_email'] ?? null,
            'before'         => $r['before'] !== null ? json_decode($r['before'], true) : null,
            'after'          => $r['after']  !== null ? json_decode($r['after'],  true) : null,
        ], $rows);
    }

    private function withVillaSchema(callable $fn): mixed
    {
        $tenant = $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => self::TENANT_SLUG]);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            return $fn();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function loginManager(): string
    {
        return $this->login(self::MANAGER, self::PASSWORD, self::HOST);
    }

    public function testManagerCanInviteEmployee(): void
    {
        $token = $this->loginManager();

        $response = $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => 'test-recep@example.sn',
                'firstName' => 'Test',
                'lastName'  => 'Réception',
                'role'      => 'RECEPTIONIST',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
        self::assertSame('test-recep@example.sn', $response['data']['email']);
        self::assertSame('pending', $response['data']['status']);

        // Vérification en base
        $this->withVillaSchema(function () {
            $invitation = $this->em->getRepository(StaffInvitation::class)
                ->findOneBy(['email' => 'test-recep@example.sn']);
            self::assertNotNull($invitation);
            self::assertSame(InvitationStatus::PENDING->value, $invitation->getStatus());
            self::assertNotEmpty($invitation->getTokenHash());
        });
    }

    public function testReceptionistCannotInvite(): void
    {
        // Réception sur Savana (Villa n'a pas de réception en fixtures)
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            'savana.localhost',
            body: [
                'email'     => 'test-recep@example.sn',
                'firstName' => 'X',
                'lastName'  => 'Y',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testInvitingExistingEmailReturnsConflict(): void
    {
        $token = $this->loginManager();

        $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => self::MANAGER, // déjà un StaffUser actif
                'firstName' => 'X',
                'lastName'  => 'Y',
                'role'      => 'RECEPTIONIST',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    public function testDuplicatePendingInvitationReturnsConflict(): void
    {
        $token = $this->loginManager();

        $payload = [
            'email'     => 'test-dup@example.sn',
            'firstName' => 'Dup',
            'lastName'  => 'Test',
            'role'      => 'HOUSEKEEPER',
        ];

        // 1ère invitation OK
        $this->apiRequest('POST', '/api/staff/invitations', self::HOST, body: $payload,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);

        // 2e identique → 409
        $this->apiRequest('POST', '/api/staff/invitations', self::HOST, body: $payload,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    public function testPublicGetValidInvitationReturnsInfo(): void
    {
        // 1. Créer une invitation
        $token = $this->loginManager();
        $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => 'test-public@example.sn',
                'firstName' => 'Public',
                'lastName'  => 'Test',
                'role'      => 'RECEPTIONIST',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        // 2. Récupérer le token réel via le service (le hash est en BDD)
        // En l'absence d'API qui le retourne, on fabrique manuellement :
        // on connaît le tokenHash, mais pas le token en clair → on simule
        // en créant une invitation directement avec un token connu.
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->withVillaSchema(function () use ($hash) {
            $inv = $this->em->getRepository(StaffInvitation::class)
                ->findOneBy(['email' => 'test-public@example.sn']);
            $inv->setTokenHash($hash);
            $this->em->flush();
        });

        $response = $this->apiRequest(
            'GET',
            "/public/invitations/$plain",
            self::HOST,
        );

        $this->assertApiSuccess($response);
        self::assertSame('test-public@example.sn', $response['data']['email']);
        self::assertSame('RECEPTIONIST', $response['data']['role']);
    }

    public function testPublicGetExpiredInvitationReturns422AndMarksExpired(): void
    {
        // Créer + forcer expiresAt dans le passé
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->withVillaSchema(function () use ($hash) {
            $inv = new StaffInvitation();
            $inv->setEmail('test-expired@example.sn');
            $inv->setFirstName('Exp');
            $inv->setLastName('Test');
            $inv->setRole('HOUSEKEEPER');
            $inv->setTokenHash($hash);
            $inv->setExpiresAt(new \DateTimeImmutable('-1 day'));
            $this->em->persist($inv);
            $this->em->flush();
        });

        $this->apiRequest('GET', "/public/invitations/$plain", self::HOST);
        $this->assertApiError('BUSINESS_RULE', 422);

        // Vérifier que l'invitation a été marquée EXPIRED
        $this->withVillaSchema(function () {
            $inv = $this->em->getRepository(StaffInvitation::class)
                ->findOneBy(['email' => 'test-expired@example.sn']);
            self::assertSame(InvitationStatus::EXPIRED->value, $inv->getStatus());
        });
    }

    public function testPublicAcceptCreatesStaffUserAndMarksAccepted(): void
    {
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->withVillaSchema(function () use ($hash) {
            $inv = new StaffInvitation();
            $inv->setEmail('test-accept@example.sn');
            $inv->setFirstName('Acc');
            $inv->setLastName('Ept');
            $inv->setRole('RECEPTIONIST');
            $inv->setTokenHash($hash);
            $this->em->persist($inv);
            $this->em->flush();
        });

        $response = $this->apiRequest(
            'POST',
            "/public/invitations/$plain/accept",
            self::HOST,
            body: ['password' => 'NewPass123!'],
        );

        $this->assertApiSuccess($response, 201);
        self::assertSame('test-accept@example.sn', $response['data']['email']);

        // Le StaffUser existe et l'invitation est ACCEPTED
        $this->withVillaSchema(function () {
            $staff = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'test-accept@example.sn']);
            self::assertNotNull($staff);
            self::assertTrue($staff->isActive());
            self::assertSame('RECEPTIONIST', $staff->getRole());

            $inv = $this->em->getRepository(StaffInvitation::class)
                ->findOneBy(['email' => 'test-accept@example.sn']);
            self::assertSame(InvitationStatus::ACCEPTED->value, $inv->getStatus());
        });

        // Login possible avec le nouveau password
        $token = $this->login('test-accept@example.sn', 'NewPass123!', self::HOST);
        self::assertNotEmpty($token);
    }

    public function testPublicAcceptTwiceReturns422(): void
    {
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->withVillaSchema(function () use ($hash) {
            $inv = new StaffInvitation();
            $inv->setEmail('test-twice@example.sn');
            $inv->setFirstName('Twi');
            $inv->setLastName('Ce');
            $inv->setRole('HOUSEKEEPER');
            $inv->setTokenHash($hash);
            $this->em->persist($inv);
            $this->em->flush();
        });

        // 1er accept OK
        $this->apiRequest('POST', "/public/invitations/$plain/accept", self::HOST,
            body: ['password' => 'Pwd12345!']);
        self::assertResponseStatusCodeSame(201);

        // 2e accept → 422
        $this->apiRequest('POST', "/public/invitations/$plain/accept", self::HOST,
            body: ['password' => 'OtherPwd!']);
        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testPlanLimitReachedReturns422(): void
    {
        // Villa Collines : plan STARTER (maxUsers=5), 1 staff actif → 4 places.
        // On crée 4 invitations PENDING pour saturer, puis on tente une 5e.
        $token = $this->loginManager();

        for ($i = 1; $i <= 4; $i++) {
            $this->apiRequest(
                'POST',
                '/api/staff/invitations',
                self::HOST,
                body: [
                    'email'     => sprintf('test-fill%d@example.sn', $i),
                    'firstName' => 'Fill',
                    'lastName'  => "$i",
                    'role'      => 'HOUSEKEEPER',
                ],
                headers: ['Authorization' => "Bearer $token"],
            );
            self::assertResponseStatusCodeSame(201, sprintf(
                'Invitation %d/4 doit passer (limite=5, staff actif=1)',
                $i,
            ));
        }

        // 5e tentative : 1 staff + 4 invitations pending = 5 → limite atteinte
        $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => 'test-overflow@example.sn',
                'firstName' => 'Over',
                'lastName'  => 'Flow',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );
        $this->assertApiError('BUSINESS_RULE', 422);
    }

    // ── Audit trail ─────────────────────────────────────────────

    public function testInviteLogsAudit(): void
    {
        $token = $this->loginManager();

        $response = $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => 'test-audit-invite@example.sn',
                'firstName' => 'AI',
                'lastName'  => 'Test',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );
        $invitationId = $response['data']['id'];

        $logs = $this->fetchAuditLogs('StaffInvitation', $invitationId);
        self::assertCount(1, $logs);
        self::assertSame('staff_invitation.created', $logs[0]['action']);
        self::assertSame(self::MANAGER, $logs[0]['staffUserEmail']);
        self::assertSame('test-audit-invite@example.sn', $logs[0]['after']['email']);
    }

    public function testAcceptLogsAuditWithoutActor(): void
    {
        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->withVillaSchema(function () use ($hash) {
            $inv = new StaffInvitation();
            $inv->setEmail('test-audit-accept@example.sn');
            $inv->setFirstName('Audit');
            $inv->setLastName('Accept');
            $inv->setRole('HOUSEKEEPER');
            $inv->setTokenHash($hash);
            $this->em->persist($inv);
            $this->em->flush();
        });

        $response = $this->apiRequest(
            'POST',
            "/public/invitations/$plain/accept",
            self::HOST,
            body: ['password' => 'AuditPwd123!'],
        );
        self::assertResponseStatusCodeSame(201);

        // Le StaffUser créé est l'entité auditée
        $newId = $this->withVillaSchema(function () {
            $staff = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'test-audit-accept@example.sn']);
            return (string) $staff->getId();
        });

        $logs = $this->fetchAuditLogs('StaffUser', $newId);
        self::assertCount(1, $logs);
        self::assertSame('staff_user.created_via_invitation', $logs[0]['action']);
        // ⚠️ Pas d'acteur loggué : l'invité n'avait pas encore de session
        self::assertNull($logs[0]['staffUserEmail']);
        self::assertSame('test-audit-accept@example.sn', $logs[0]['after']['email']);
    }

    public function testRevokeLogsAudit(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest(
            'POST',
            '/api/staff/invitations',
            self::HOST,
            body: [
                'email'     => 'test-audit-revoke@example.sn',
                'firstName' => 'AR',
                'lastName'  => 'Test',
                'role'      => 'HOUSEKEEPER',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );
        $invitationId = $created['data']['id'];

        $this->apiRequest(
            'POST',
            "/api/staff/invitations/$invitationId/revoke",
            self::HOST,
            headers: ['Authorization' => "Bearer $token"],
        );
        self::assertResponseStatusCodeSame(200);

        $logs = $this->fetchAuditLogs('StaffInvitation', $invitationId);
        // [0] = revoke, [1] = create
        self::assertSame('staff_invitation.revoked', $logs[0]['action']);
        self::assertSame(self::MANAGER, $logs[0]['staffUserEmail']);
        self::assertSame('pending',  $logs[0]['before']['status']);
        self::assertSame('revoked',  $logs[0]['after']['status']);
    }
}
