<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Tenant;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — GET / PATCH /api/tenant/settings (Sprint 14-A.2).
 *
 * On travaille sur Savana (manager + réceptionniste fixtures). Avant et après
 * chaque test on restaure les politiques par défaut (`first_night` /
 * `flexible` / `5`) côté public.tenants, et on purge les audit logs
 * `tenant.settings_updated` du schema savana pour des assertions propres.
 */
class TenantSettingsControllerTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST            = 'savana.localhost';
    private const MANAGER         = 'admin@savana-hotel.sn';
    private const MANAGER_PWD     = 'admin123';
    private const RECEPTIONIST    = 'reception@savana-hotel.sn';
    private const RECEPTIONIST_PWD = 'recep123';

    private const VILLA_HOST      = 'villa-collines.localhost';
    private const VILLA_MANAGER   = 'admin@villa-collines.sn';
    private const VILLA_PWD       = 'admin123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        self::assertNotNull($tenant, 'Fixture tenant "savana" requise');
        $this->schema = $tenant->getSchemaName();

        $this->restoreDefaults();
        $this->purgeAuditLogs();
    }

    protected function tearDown(): void
    {
        try {
            $this->restoreDefaults();
            $this->purgeAuditLogs();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Restaure les valeurs par défaut sur les 2 tenants pour éviter de polluer
     * les fixtures partagées entre tests.
     */
    private function restoreDefaults(): void
    {
        $defaults = json_encode([
            'no_show_policy'           => 'first_night',
            'cancellation_policy'      => 'flexible',
            'business_day_cutoff_hour' => 5,
        ], JSON_THROW_ON_ERROR);

        $this->conn->executeStatement(
            "UPDATE public.tenants
             SET settings = COALESCE(settings, '{}'::json)::jsonb || :patch::jsonb
             WHERE slug IN ('savana', 'villa-collines')",
            ['patch' => $defaults]
        );
        $this->em->clear();
    }

    private function purgeAuditLogs(): void
    {
        foreach (['savana', 'villa-collines'] as $slug) {
            $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
            if ($tenant === null) {
                continue;
            }
            $schema = $tenant->getSchemaName();
            $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
            try {
                $this->conn->executeStatement(
                    "DELETE FROM audit_logs
                     WHERE entity_type IN ('Tenant','tenant') AND action = 'tenant.settings_updated'"
                );
            } finally {
                $this->conn->executeStatement('SET search_path TO public');
            }
        }
        $this->em->clear();
    }

    /**
     * @return list<array{action: string, before: ?array, after: ?array}>
     */
    private function fetchAuditLogs(string $tenantSlug): array
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => $tenantSlug]);
        self::assertNotNull($tenant);
        $schema = $tenant->getSchemaName();

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $rows = $this->conn->fetchAllAssociative(
                "SELECT action, before, after
                 FROM audit_logs
                 WHERE entity_type = 'Tenant'
                   AND action = 'tenant.settings_updated'
                 ORDER BY created_at DESC, id DESC"
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        return array_map(fn (array $r) => [
            'action' => $r['action'],
            'before' => $r['before'] !== null ? json_decode($r['before'], true) : null,
            'after'  => $r['after']  !== null ? json_decode($r['after'],  true) : null,
        ], $rows);
    }

    /**
     * Lit les settings JSONB d'un tenant directement en BDD.
     *
     * @return array<string, mixed>
     */
    private function readSettings(string $slug): array
    {
        $row = $this->conn->fetchOne(
            'SELECT settings FROM public.tenants WHERE slug = ?',
            [$slug]
        );
        return $row !== false ? (json_decode((string) $row, true) ?? []) : [];
    }

    // ─────────────────────────────────────────────────────────────────
    //  GET
    // ─────────────────────────────────────────────────────────────────

    public function testGetSettingsRequiresAuthentication(): void
    {
        $this->client->request(
            'GET', '/api/tenant/settings',
            server: ['HTTP_HOST' => self::HOST]
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testGetSettingsReturnsAllFields(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'GET', '/api/tenant/settings', self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        $data = $response['data'];
        self::assertSame('first_night', $data['noShowPolicy']);
        self::assertSame('flexible',    $data['cancellationPolicy']);
        self::assertSame(5,             $data['businessDayCutoffHour']);
        self::assertArrayHasKey('timezone', $data);
        self::assertArrayHasKey('currency', $data);
    }

    // ─────────────────────────────────────────────────────────────────
    //  PATCH — RBAC
    // ─────────────────────────────────────────────────────────────────

    public function testPatchSettingsRequiresManagerRole(): void
    {
        $token = $this->login(self::RECEPTIONIST, self::RECEPTIONIST_PWD, self::HOST);

        $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body:    ['noShowPolicy' => 'full'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('ACCESS_DENIED', 403);

        // Vérifier que rien n'a changé en BDD
        $settings = $this->readSettings('savana');
        self::assertSame('first_night', $settings['no_show_policy'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────
    //  PATCH — Succès (full + partial)
    // ─────────────────────────────────────────────────────────────────

    public function testPatchSettingsAcceptsFullPayload(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body: [
                'noShowPolicy'          => 'full',
                'cancellationPolicy'    => 'strict',
                'businessDayCutoffHour' => 3,
            ],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertSame('full',   $response['data']['noShowPolicy']);
        self::assertSame('strict', $response['data']['cancellationPolicy']);
        self::assertSame(3,        $response['data']['businessDayCutoffHour']);

        // BDD à jour
        $settings = $this->readSettings('savana');
        self::assertSame('full',   $settings['no_show_policy']);
        self::assertSame('strict', $settings['cancellation_policy']);
        self::assertSame(3,        $settings['business_day_cutoff_hour']);

        // Audit log présent avec les 3 diffs
        $logs = $this->fetchAuditLogs('savana');
        self::assertCount(1, $logs);
        self::assertSame('tenant.settings_updated', $logs[0]['action']);
        self::assertSame('first_night', $logs[0]['before']['noShowPolicy']);
        self::assertSame('full',        $logs[0]['after']['noShowPolicy']);
        self::assertSame('flexible',    $logs[0]['before']['cancellationPolicy']);
        self::assertSame('strict',      $logs[0]['after']['cancellationPolicy']);
        self::assertSame(5,             $logs[0]['before']['businessDayCutoffHour']);
        self::assertSame(3,             $logs[0]['after']['businessDayCutoffHour']);
    }

    public function testPatchSettingsAcceptsPartialPayload(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body:    ['noShowPolicy' => 'none'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertSame('none',     $response['data']['noShowPolicy']);
        self::assertSame('flexible', $response['data']['cancellationPolicy']); // inchangé
        self::assertSame(5,          $response['data']['businessDayCutoffHour']); // inchangé

        // BDD : seul no_show_policy a bougé
        $settings = $this->readSettings('savana');
        self::assertSame('none',        $settings['no_show_policy']);
        self::assertSame('flexible',    $settings['cancellation_policy']);
        self::assertSame(5,             $settings['business_day_cutoff_hour']);

        // Audit log : UNIQUEMENT le diff du champ changé
        $logs = $this->fetchAuditLogs('savana');
        self::assertCount(1, $logs);
        self::assertSame(['noShowPolicy' => 'first_night'], $logs[0]['before']);
        self::assertSame(['noShowPolicy' => 'none'],        $logs[0]['after']);
    }

    // ─────────────────────────────────────────────────────────────────
    //  PATCH — Validation
    // ─────────────────────────────────────────────────────────────────

    public function testPatchSettingsRejectsInvalidEnum(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body:    ['noShowPolicy' => 'invalid_value'],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('VALIDATION_ERROR', 422);

        // BDD intacte
        $settings = $this->readSettings('savana');
        self::assertSame('first_night', $settings['no_show_policy']);
        self::assertCount(0, $this->fetchAuditLogs('savana'));
    }

    public function testPatchSettingsRejectsInvalidCutoffHour(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body:    ['businessDayCutoffHour' => 25],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('VALIDATION_ERROR', 422);

        $settings = $this->readSettings('savana');
        self::assertSame(5, $settings['business_day_cutoff_hour']);
    }

    public function testPatchSettingsRejectsEmptyPayload(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        // Forcer un body "{}" explicite (apiRequest avec body=[] envoie null)
        $this->client->request(
            'PATCH', '/api/tenant/settings',
            server: [
                'HTTP_HOST'          => self::HOST,
                'HTTP_AUTHORIZATION' => "Bearer $token",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: '{}'
        );

        $this->assertApiError('BUSINESS_RULE', 422);

        // Audit log toujours vide
        self::assertCount(0, $this->fetchAuditLogs('savana'));
    }

    // ─────────────────────────────────────────────────────────────────
    //  PATCH — No-op (pas d'entrée audit log fantôme)
    // ─────────────────────────────────────────────────────────────────

    public function testPatchSettingsNoOpDoesNotWriteAudit(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $response = $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body: [
                // Valeurs identiques aux defaults
                'noShowPolicy'          => 'first_night',
                'cancellationPolicy'    => 'flexible',
                'businessDayCutoffHour' => 5,
            ],
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($response);
        self::assertCount(0, $this->fetchAuditLogs('savana'),
            "Un PATCH no-op ne doit PAS créer d'entrée audit log."
        );
    }

    // ─────────────────────────────────────────────────────────────────
    //  PATCH — Isolation multi-tenant
    // ─────────────────────────────────────────────────────────────────

    public function testPatchSettingsIsCrossTenantIsolated(): void
    {
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->apiRequest(
            'PATCH', '/api/tenant/settings', self::HOST,
            body:    ['noShowPolicy' => 'full', 'cancellationPolicy' => 'strict'],
            headers: ['Authorization' => "Bearer $token"]
        );
        self::assertResponseStatusCodeSame(200);

        // Savana impacté
        $savana = $this->readSettings('savana');
        self::assertSame('full',   $savana['no_show_policy']);
        self::assertSame('strict', $savana['cancellation_policy']);

        // Villa Collines inchangé
        $villa = $this->readSettings('villa-collines');
        self::assertSame('first_night', $villa['no_show_policy']);
        self::assertSame('flexible',    $villa['cancellation_policy']);

        // Audit log Villa vide
        self::assertCount(0, $this->fetchAuditLogs('villa-collines'));
    }
}
