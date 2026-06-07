<?php

declare(strict_types=1);

namespace App\Tests\Functional\SuperAdmin;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Tests\Functional\ApiTestCase;
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

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Repartir d'un état propre : villa-collines ACTIVE
        $this->forceVillaCollinesActive();
    }

    protected function tearDown(): void
    {
        $this->forceVillaCollinesActive();

        parent::tearDown();
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
}
