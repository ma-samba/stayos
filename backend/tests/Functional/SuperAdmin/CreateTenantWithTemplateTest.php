<?php

declare(strict_types=1);

namespace App\Tests\Functional\SuperAdmin;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sprint 13ter — vérifie que `POST /superadmin/tenants` avec
 * `seed_template` pré-remplit correctement le schema tenant.
 *
 * @group integration
 */
class CreateTenantWithTemplateTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->cleanupTestTenants();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupTestTenants();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupTestTenants(): void
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT id FROM tenants WHERE slug LIKE 'sa-tpl-%'"
        );
        foreach ($rows as $row) {
            $schema = 'hotel_' . str_replace('-', '_', $row['id']);
            $this->conn->executeStatement("DROP SCHEMA IF EXISTS $schema CASCADE");
        }
        $this->conn->executeStatement("DELETE FROM saas_invoices WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'sa-tpl-%')");
        $this->conn->executeStatement("DELETE FROM subscriptions WHERE tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'sa-tpl-%')");
        $this->conn->executeStatement("DELETE FROM tenants WHERE slug LIKE 'sa-tpl-%'");
        $this->conn->executeStatement("DELETE FROM superadmin_audit_log WHERE tenant_slug LIKE 'sa-tpl-%'");
        $this->em->clear();
    }

    private function loginSuperAdmin(): string
    {
        $this->client->request(
            method: 'POST',
            uri:    '/superadmin/auth/login',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'localhost'],
            content: json_encode(['email' => 'admin@stayos.sn', 'password' => 'superadmin123']),
        );
        $resp = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
        return $resp['token'] ?? '';
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function createTenant(string $token, array $body): array
    {
        $this->client->request(
            method: 'POST',
            uri: '/superadmin/tenants',
            server: [
                'HTTP_HOST'          => 'localhost',
                'CONTENT_TYPE'       => 'application/json',
                'HTTP_AUTHORIZATION' => "Bearer $token",
            ],
            content: json_encode($body),
        );
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    /**
     * @return array{floors:int, types:int, rooms:int}
     */
    private function countSeed(string $slug): array
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($tenant);
        $schema = $tenant->getSchemaName();

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            return [
                'floors' => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM floors'),
                'types'  => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM room_types'),
                'rooms'  => (int) $this->conn->fetchOne('SELECT COUNT(*) FROM rooms'),
            ];
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testCreateTenantWithEmptyTemplate(): void
    {
        $token = $this->loginSuperAdmin();

        $resp = $this->createTenant($token, [
            'hotel_name'         => 'Empty Hotel',
            'slug'               => 'sa-tpl-empty',
            'manager_email'      => 'mgr-empty@example.sn',
            'manager_first_name' => 'Empty',
            'manager_last_name'  => 'Test',
            'plan'               => 'STARTER',
            'initial_status'     => 'active',
            // seed_template absent → default 'empty'
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('empty', $resp['data']['seed_template'] ?? null);

        $counts = $this->countSeed('sa-tpl-empty');
        self::assertSame(0, $counts['floors']);
        self::assertSame(0, $counts['types']);
        self::assertSame(0, $counts['rooms']);
    }

    public function testCreateTenantWithSmallTemplate(): void
    {
        $token = $this->loginSuperAdmin();

        $resp = $this->createTenant($token, [
            'hotel_name'         => 'Small Hotel',
            'slug'               => 'sa-tpl-small',
            'manager_email'      => 'mgr-small@example.sn',
            'manager_first_name' => 'Small',
            'manager_last_name'  => 'Test',
            'plan'               => 'STARTER',
            'initial_status'     => 'active',
            'seed_template'      => 'small_hotel',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('small_hotel', $resp['data']['seed_template'] ?? null);

        $counts = $this->countSeed('sa-tpl-small');
        self::assertSame(1, $counts['floors']);
        self::assertSame(1, $counts['types']);
        self::assertSame(5, $counts['rooms']);
    }

    public function testCreateTenantWithMediumTemplate(): void
    {
        $token = $this->loginSuperAdmin();

        $resp = $this->createTenant($token, [
            'hotel_name'         => 'Medium Hotel',
            'slug'               => 'sa-tpl-medium',
            'manager_email'      => 'mgr-medium@example.sn',
            'manager_first_name' => 'Medium',
            'manager_last_name'  => 'Test',
            'plan'               => 'PRO',
            'initial_status'     => 'active',
            'seed_template'      => 'medium_hotel',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('medium_hotel', $resp['data']['seed_template'] ?? null);

        $counts = $this->countSeed('sa-tpl-medium');
        self::assertSame(2, $counts['floors']);
        self::assertSame(2, $counts['types']);
        self::assertSame(12, $counts['rooms']);
    }

    public function testInvalidTemplateRejected(): void
    {
        $token = $this->loginSuperAdmin();

        $this->createTenant($token, [
            'hotel_name'         => 'Invalid Tpl',
            'slug'               => 'sa-tpl-invalid',
            'manager_email'      => 'mgr-bad@example.sn',
            'manager_first_name' => 'Bad',
            'manager_last_name'  => 'Test',
            'plan'               => 'STARTER',
            'initial_status'     => 'active',
            'seed_template'      => 'unknown_template',
        ]);

        self::assertResponseStatusCodeSame(422);
    }
}
