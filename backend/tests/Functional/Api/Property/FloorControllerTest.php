<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Property;

use App\Hotel\Property\Domain\Entity\Floor;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sprint 13ter — Tests CRUD des étages côté manager.
 *
 * @group integration
 */
class FloorControllerTest extends ApiTestCase
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
        $this->cleanupTestFloors();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupTestFloors();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupTestFloors(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        if ($tenant === null) {
            return;
        }
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                "DELETE FROM audit_logs WHERE entity_type = 'Floor'"
            );
            // Garbage des étages créés par les tests (numéros 90-99 réservés)
            $this->conn->executeStatement(
                'DELETE FROM floors WHERE number BETWEEN 90 AND 99'
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function loginManager(): string
    {
        return $this->login(self::MANAGER, self::PASSWORD, self::HOST);
    }

    public function testManagerCanCRUDFloors(): void
    {
        $token = $this->loginManager();

        // CREATE
        $created = $this->apiRequest('POST', '/api/floors', self::HOST,
            body: ['number' => 95, 'name' => 'Étage test'],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($created, 201);
        self::assertSame(95, $created['data']['number']);
        $id = $created['data']['id'];

        // READ (list)
        $list = $this->apiRequest('GET', '/api/floors', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($list);
        $numbers = array_column($list['data'], 'number');
        self::assertContains(95, $numbers);

        // UPDATE
        $updated = $this->apiRequest('PUT', "/api/floors/$id", self::HOST,
            body: ['name' => 'Étage rénové'],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($updated);
        self::assertSame('Étage rénové', $updated['data']['name']);

        // DELETE (pas de chambres rattachées → OK)
        $this->apiRequest('DELETE', "/api/floors/$id", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testReceptionistCannotWriteFloors(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);

        $this->apiRequest('POST', '/api/floors', self::HOST,
            body: ['number' => 91],
            headers: ['Authorization' => "Bearer $token"]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testReceptionistCanReadFloors(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);

        $response = $this->apiRequest('GET', '/api/floors', self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiSuccess($response);
    }

    public function testDuplicateNumberRejected(): void
    {
        $token = $this->loginManager();

        $this->apiRequest('POST', '/api/floors', self::HOST,
            body: ['number' => 96],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);

        $this->apiRequest('POST', '/api/floors', self::HOST,
            body: ['number' => 96],
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    public function testDeleteWithRoomsRejected(): void
    {
        $token = $this->loginManager();

        // L'étage 1 de Savana a des chambres en fixtures → DELETE doit échouer
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $floor = $this->em->getRepository(Floor::class)->findOneBy(['number' => 1]);
            self::assertNotNull($floor, 'Fixture savana doit avoir un étage 1');
            $floorId = (string) $floor->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $response = $this->apiRequest('DELETE', "/api/floors/$floorId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString('chambre', $response['error'] ?? '');
    }
}
