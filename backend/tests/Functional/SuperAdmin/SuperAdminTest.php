<?php

declare(strict_types=1);

namespace App\Tests\Functional\SuperAdmin;

use App\Platform\Admin\Domain\Entity\SuperAdminAuditLog;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels du back-office SuperAdmin.
 *
 * Les tests modifient le statut de villa-collines. Le setUp et le
 * tearDown restaurent l'état ACTIVE pour ne pas polluer les fixtures
 * partagées avec les autres suites.
 *
 * @group integration
 */
class SuperAdminTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        // Repartir d'un état propre : villa-collines ACTIVE,
        // tenants test purgés, audit log purgé.
        $this->cleanupTestTenants();
        $this->forceVillaCollinesActive();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestTenants();
        $this->forceVillaCollinesActive();

        parent::tearDown();
    }

    /**
     * Purge les tenants créés via les tests (slug 'sa-test-...') +
     * les audit logs SuperAdmin liés aux tests, pour éviter la
     * contamination entre runs.
     */
    private function cleanupTestTenants(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id, schema_name_hint FROM (
                SELECT id, slug AS schema_name_hint FROM tenants
                WHERE slug LIKE 'sa-test-%'
            ) t"
        );

        foreach ($rows as $row) {
            $schema = 'hotel_' . str_replace('-', '_', $row['id']);
            $this->conn->executeStatement("DROP SCHEMA IF EXISTS $schema CASCADE");
        }

        $this->conn->executeStatement("DELETE FROM saas_invoices WHERE tenant_id IN (
            SELECT id FROM tenants WHERE slug LIKE 'sa-test-%'
        )");
        $this->conn->executeStatement("DELETE FROM subscriptions WHERE tenant_id IN (
            SELECT id FROM tenants WHERE slug LIKE 'sa-test-%'
        )");
        $this->conn->executeStatement("DELETE FROM tenants WHERE slug LIKE 'sa-test-%'");
        $this->conn->executeStatement(
            "DELETE FROM superadmin_audit_log WHERE tenant_slug LIKE 'sa-test-%'"
        );
        $this->em->clear();
    }

    private function forceVillaCollinesActive(): void
    {
        /** @var TenantRepository $repo */
        $repo   = $this->em->getRepository(Tenant::class);
        $tenant = $repo->findBySlug('villa-collines');

        if ($tenant !== null && $tenant->getStatus() !== TenantStatus::ACTIVE->value) {
            $tenant->setStatus(TenantStatus::ACTIVE);
        }

        // Si une subscription a été basculée en 'suspended', la remettre 'active'
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['tenant' => $tenant, 'status' => 'suspended']);
        if ($sub !== null) {
            $sub->setStatus('active');
        }

        $this->em->flush();
        $this->em->clear();
    }

    private function loginSuperAdmin(): string
    {
        $this->client->request(
            method: 'POST',
            uri:    '/superadmin/auth/login',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_HOST'    => 'localhost',
            ],
            content: json_encode(['email' => 'admin@stayos.sn', 'password' => 'superadmin123']),
        );

        $response = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];

        return $response['token'] ?? '';
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function adminRequest(string $method, string $url, array $headers, ?array $body = null): array
    {
        $server = [
            'HTTP_HOST'    => 'localhost',
            'CONTENT_TYPE' => 'application/json',
        ];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        $this->client->request(
            method:  $method,
            uri:     $url,
            server:  $server,
            content: $body === null ? null : json_encode($body),
        );

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testStaffUserCannotAccessSuperadmin(): void
    {
        $staffToken = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');
        self::assertNotEmpty($staffToken, 'Login Savana doit fonctionner');

        $this->adminRequest('GET', '/superadmin/tenants', ['Authorization' => "Bearer $staffToken"]);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains(
            $status,
            [401, 403],
            "Un JWT staff ne doit pas accéder à /superadmin (reçu : $status)",
        );
    }

    public function testSuperAdminCanLoginAndListTenants(): void
    {
        $token = $this->loginSuperAdmin();
        self::assertNotEmpty($token, 'SuperAdmin doit pouvoir se loguer');

        $response = $this->adminRequest('GET', '/superadmin/tenants', ['Authorization' => "Bearer $token"]);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('data', $response);
        self::assertGreaterThanOrEqual(2, count($response['data']));

        $slugs = array_column($response['data'], 'slug');
        self::assertContains('savana', $slugs);
        self::assertContains('villa-collines', $slugs);
    }

    public function testSuperAdminCanFilterByStatus(): void
    {
        // Mettre temporairement villa-collines en TRIAL pour le filtre
        /** @var Tenant $villa */
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        $villa->setStatus(TenantStatus::TRIAL);
        $this->em->flush();
        $this->em->clear();

        try {
            $token    = $this->loginSuperAdmin();
            $response = $this->adminRequest(
                'GET',
                '/superadmin/tenants?status=trial',
                ['Authorization' => "Bearer $token"],
            );

            self::assertResponseStatusCodeSame(200);
            foreach ($response['data'] as $tenantData) {
                self::assertSame('trial', $tenantData['status']);
            }

            $slugs = array_column($response['data'], 'slug');
            self::assertContains('villa-collines', $slugs);
            self::assertNotContains('savana', $slugs);
        } finally {
            // Restaurer ACTIVE
            $this->forceVillaCollinesActive();
        }
    }

    public function testSuperAdminCanSuspendTenant(): void
    {
        $token = $this->loginSuperAdmin();

        $response = $this->adminRequest(
            'POST',
            '/superadmin/tenants/villa-collines/suspend',
            ['Authorization' => "Bearer $token"],
            ['reason' => 'Test automatique'],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame('suspended', $response['data']['status']);

        // Vérification BDD
        $this->em->clear();
        /** @var Tenant $villa */
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        self::assertSame(TenantStatus::SUSPENDED->value, $villa->getStatus());

        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['tenant' => $villa], ['createdAt' => 'DESC']);
        self::assertNotNull($sub);
        self::assertSame('suspended', $sub->getStatus());
    }

    public function testSuspendedTenantReturns402(): void
    {
        // Suspendre villa-collines en direct BDD pour préparer le test
        /** @var Tenant $villa */
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        $villa->setStatus(TenantStatus::SUSPENDED);
        $this->em->flush();
        $this->em->clear();

        // Le manager tente d'accéder à un endpoint protégé
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        // Login peut réussir (search_path résolu via JWTDecodedListener)
        // mais l'accès à un endpoint protégé doit retourner 402.
        if ($token === '') {
            // Login a échoué directement (TenantMiddleware a stoppé) :
            // c'est aussi un comportement acceptable.
            self::assertSame(402, $this->client->getResponse()->getStatusCode());
            return;
        }

        $this->apiRequest(
            'GET',
            '/api/dashboard/today',
            'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertSame(402, $this->client->getResponse()->getStatusCode());
    }

    public function testSuperAdminCanReactivateTenant(): void
    {
        // Préparation : suspendre d'abord
        /** @var Tenant $villa */
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        $villa->setStatus(TenantStatus::SUSPENDED);
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['tenant' => $villa], ['createdAt' => 'DESC']);
        $sub?->setStatus('suspended');
        $this->em->flush();
        $this->em->clear();

        $token = $this->loginSuperAdmin();
        $response = $this->adminRequest(
            'POST',
            '/superadmin/tenants/villa-collines/reactivate',
            ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame('active', $response['data']['status']);

        $this->em->clear();
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        self::assertSame(TenantStatus::ACTIVE->value, $villa->getStatus());

        // Le manager peut à nouveau accéder à son hôtel
        $managerToken = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');
        self::assertNotEmpty($managerToken);
    }

    public function testSuspendIsIdempotent(): void
    {
        $token = $this->loginSuperAdmin();

        $first = $this->adminRequest(
            'POST',
            '/superadmin/tenants/villa-collines/suspend',
            ['Authorization' => "Bearer $token"],
        );
        self::assertResponseStatusCodeSame(200);
        self::assertSame('suspended', $first['data']['status']);

        // 2e POST → 422 BUSINESS_RULE
        $second = $this->adminRequest(
            'POST',
            '/superadmin/tenants/villa-collines/suspend',
            ['Authorization' => "Bearer $token"],
        );
        self::assertResponseStatusCodeSame(422);
        self::assertSame('BUSINESS_RULE', $second['code'] ?? null);
    }

    public function testMetricsEndpointReturnsExpectedShape(): void
    {
        $token = $this->loginSuperAdmin();

        $response = $this->adminRequest(
            'GET',
            '/superadmin/metrics',
            ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(200);
        $data = $response['data'] ?? [];

        foreach ([
            'mrr',
            'activeTenantsCount',
            'trialTenantsCount',
            'suspendedTenantsCount',
            'cancelledTenantsCount',
            'newTenantsLast30Days',
            'churnLast30Days',
            'planDistribution',
        ] as $key) {
            self::assertArrayHasKey($key, $data, "metrics doit exposer $key");
        }

        self::assertArrayHasKey('STARTER', $data['planDistribution']);
        self::assertArrayHasKey('PRO', $data['planDistribution']);
        self::assertArrayHasKey('ENTERPRISE', $data['planDistribution']);

        // 2 tenants actifs en fixtures : savana + villa-collines
        self::assertGreaterThanOrEqual(2, $data['activeTenantsCount']);
    }

    // ── Sprint 13bis-B : création, édition, force plan, audit ──

    public function testSuperAdminCanCreateTenant(): void
    {
        $token = $this->loginSuperAdmin();

        $response = $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'Hotel SA Test 1',
                'slug'               => 'sa-test-create',
                'manager_email'      => 'manager@sa-test-create.sn',
                'manager_first_name' => 'Test',
                'manager_last_name'  => 'Manager',
                'plan'               => 'STARTER',
                'initial_status'    => 'trial',
            ],
        );

        self::assertResponseStatusCodeSame(201);
        self::assertSame('sa-test-create', $response['data']['tenant']['slug']);
        self::assertSame('trial', $response['data']['tenant']['status']);
        self::assertSame(16, strlen($response['data']['manager_password']));

        // Vérification en BDD : tenant + schema + subscription trial + staff
        $this->em->clear();
        /** @var Tenant $tenant */
        $tenant = $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => 'sa-test-create']);
        self::assertNotNull($tenant);

        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($sub);
        self::assertSame('trial', $sub->getStatus());

        // Vérifier staff dans le schema tenant
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $count = (int) $this->conn->fetchOne(
                "SELECT COUNT(*) FROM staff_users WHERE email = ? AND role = 'MANAGER'",
                ['manager@sa-test-create.sn'],
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
        self::assertSame(1, $count, 'Le staff MANAGER doit être créé');

        // Audit log écrit
        $audits = $this->em->getRepository(SuperAdminAuditLog::class)
            ->findBy(['tenantSlug' => 'sa-test-create'], ['createdAt' => 'DESC']);
        self::assertNotEmpty($audits);
        self::assertSame('tenant.created', $audits[0]->getAction());
        self::assertSame('admin@stayos.sn', $audits[0]->getActorEmail());
    }

    public function testCreateTenantWithActiveStatus(): void
    {
        $token = $this->loginSuperAdmin();

        $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'Hotel SA Test Active',
                'slug'               => 'sa-test-active',
                'manager_email'      => 'manager@sa-test-active.sn',
                'manager_first_name' => 'Active',
                'manager_last_name'  => 'Mgr',
                'plan'               => 'PRO',
                'initial_status'     => 'active',
            ],
        );
        self::assertResponseStatusCodeSame(201);

        $this->em->clear();
        $tenant = $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => 'sa-test-active']);
        $sub = $this->em->getRepository(Subscription::class)
            ->findOneBy(['tenant' => $tenant]);
        self::assertSame('active', $sub->getStatus());
        self::assertNotNull($sub->getCurrentPeriodEnd());

        // Période fin ~+30j
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
        $diff   = $sub->getCurrentPeriodEnd()->getTimestamp() - $now->getTimestamp();
        $days   = (int) round($diff / 86400);
        self::assertGreaterThanOrEqual(29, $days);
        self::assertLessThanOrEqual(31, $days);
    }

    public function testCreateTenantSlugAlreadyExists(): void
    {
        $token = $this->loginSuperAdmin();

        $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'X',
                'slug'               => 'savana', // déjà pris
                'manager_email'      => 'x@x.sn',
                'manager_first_name' => 'X',
                'manager_last_name'  => 'Y',
                'plan'               => 'STARTER',
                'initial_status'     => 'trial',
            ],
        );

        $response = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ALREADY_EXISTS', $response['code'] ?? null);
    }

    public function testCreateTenantValidation(): void
    {
        $token = $this->loginSuperAdmin();

        // Slug invalide (underscore, non couvert par la normalisation
        // côté serveur qui n'applique que lowercase + trim)
        $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'X',
                'slug'               => 'sa_test_underscore',
                'manager_email'      => 'x@x.sn',
                'manager_first_name' => 'X',
                'manager_last_name'  => 'Y',
                'plan'               => 'STARTER',
                'initial_status'     => 'trial',
            ],
        );
        self::assertResponseStatusCodeSame(422);

        // Plan invalide
        $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'X',
                'slug'               => 'sa-test-bad-plan',
                'manager_email'      => 'x@x.sn',
                'manager_first_name' => 'X',
                'manager_last_name'  => 'Y',
                'plan'               => 'WTF',
                'initial_status'     => 'trial',
            ],
        );
        self::assertResponseStatusCodeSame(422);

        // Email invalide
        $this->adminRequest('POST', '/superadmin/tenants',
            ['Authorization' => "Bearer $token"],
            [
                'hotel_name'         => 'X',
                'slug'               => 'sa-test-bad-email',
                'manager_email'      => 'not-an-email',
                'manager_first_name' => 'X',
                'manager_last_name'  => 'Y',
                'plan'               => 'STARTER',
                'initial_status'     => 'trial',
            ],
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuperAdminCanUpdateTenant(): void
    {
        $token = $this->loginSuperAdmin();

        $response = $this->adminRequest(
            'PATCH',
            '/superadmin/tenants/villa-collines',
            ['Authorization' => "Bearer $token"],
            ['name' => 'Villa Collines (modifié)'],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Villa Collines (modifié)', $response['data']['name']);

        $this->em->clear();
        $audits = $this->em->getRepository(SuperAdminAuditLog::class)
            ->findBy(['tenantSlug' => 'villa-collines', 'action' => 'tenant.updated'],
                ['createdAt' => 'DESC']);
        self::assertNotEmpty($audits);
        $payload = $audits[0]->getPayload();
        self::assertArrayHasKey('before', $payload);
        self::assertArrayHasKey('after',  $payload);
        self::assertSame('Villa Collines (modifié)', $payload['after']['name']);

        // Restore le nom d'origine + purger audit
        $this->em->getRepository(Tenant::class)
            ->findOneBy(['slug' => 'villa-collines'])
            ->setName('Villa Collines Saly');
        $this->em->flush();
        $this->conn->executeStatement(
            "DELETE FROM superadmin_audit_log WHERE tenant_slug = 'villa-collines'"
        );
    }

    public function testUpdateTenantNoFieldsReturns422(): void
    {
        $token = $this->loginSuperAdmin();

        $this->adminRequest('PATCH', '/superadmin/tenants/villa-collines',
            ['Authorization' => "Bearer $token"], []);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSuperAdminCanForcePlan(): void
    {
        $token = $this->loginSuperAdmin();

        $response = $this->adminRequest(
            'POST',
            '/superadmin/tenants/villa-collines/force-plan',
            ['Authorization' => "Bearer $token"],
            [
                'plan'   => 'PRO',
                'reason' => 'Geste commercial Q3 — démo grand compte X',
            ],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertSame('PRO', $response['data']['planName']);

        $this->em->clear();
        $audits = $this->em->getRepository(SuperAdminAuditLog::class)
            ->findBy(['tenantSlug' => 'villa-collines', 'action' => 'subscription.force_plan'],
                ['createdAt' => 'DESC']);
        self::assertNotEmpty($audits);
        $payload = $audits[0]->getPayload();
        self::assertSame('PRO',     $payload['planTo']);
        self::assertSame('STARTER', $payload['planFrom']);
        self::assertStringContainsString('Geste commercial', $payload['reason']);

        // Restore villa-collines sur STARTER
        $starter = $this->em->getRepository(\App\Platform\Subscription\Domain\Entity\Plan::class)
            ->findOneBy(['name' => 'STARTER']);
        $villa = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'villa-collines']);
        $sub = $this->em->getRepository(Subscription::class)->findOneBy(['tenant' => $villa]);
        $sub->setPlan($starter);
        $this->em->flush();
        $this->conn->executeStatement(
            "DELETE FROM superadmin_audit_log WHERE tenant_slug = 'villa-collines'"
        );
    }

    public function testForcePlanRequiresReason(): void
    {
        $token = $this->loginSuperAdmin();

        // Pas de reason
        $this->adminRequest('POST', '/superadmin/tenants/villa-collines/force-plan',
            ['Authorization' => "Bearer $token"], ['plan' => 'PRO']);
        self::assertResponseStatusCodeSame(422);

        // Reason trop courte
        $this->adminRequest('POST', '/superadmin/tenants/villa-collines/force-plan',
            ['Authorization' => "Bearer $token"], ['plan' => 'PRO', 'reason' => 'X']);
        self::assertResponseStatusCodeSame(422);
    }

    public function testAuditEndpointReturnsActions(): void
    {
        $token = $this->loginSuperAdmin();

        // Déclencher une action pour avoir une entrée
        $this->adminRequest('POST', '/superadmin/tenants/villa-collines/suspend',
            ['Authorization' => "Bearer $token"],
            ['reason' => 'test audit endpoint'],
        );

        $response = $this->adminRequest('GET', '/superadmin/audit?tenant_slug=villa-collines',
            ['Authorization' => "Bearer $token"]);

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('meta', $response);
        self::assertGreaterThanOrEqual(1, $response['meta']['total']);

        $actions = array_column($response['data'], 'action');
        self::assertContains('tenant.suspended', $actions);

        // Le payload IP doit être renseigné
        $first = $response['data'][0];
        self::assertNotNull($first['ipAddress']);
        self::assertSame('admin@stayos.sn', $first['actorEmail']);
    }

    public function testAuditEndpointRequiresSuperAdmin(): void
    {
        // JWT staff (manager Savana) ne doit PAS accéder au journal d'audit
        $staffToken = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $this->adminRequest('GET', '/superadmin/audit',
            ['Authorization' => "Bearer $staffToken"]);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [401, 403]);
    }

    public function testSuspendNowWritesToAudit(): void
    {
        $token = $this->loginSuperAdmin();

        $this->adminRequest('POST', '/superadmin/tenants/villa-collines/suspend',
            ['Authorization' => "Bearer $token"],
            ['reason' => 'régression audit Sprint 13bis-B'],
        );

        $this->em->clear();
        $audits = $this->em->getRepository(SuperAdminAuditLog::class)
            ->findBy(['tenantSlug' => 'villa-collines', 'action' => 'tenant.suspended'],
                ['createdAt' => 'DESC']);

        self::assertNotEmpty(
            $audits,
            'Sprint 13 a livré suspend SANS audit log ; ce sprint corrige.',
        );
        self::assertSame('régression audit Sprint 13bis-B', $audits[0]->getPayload()['reason']);
    }
}
