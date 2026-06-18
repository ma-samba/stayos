<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Room;

use App\Hotel\Room\Domain\Entity\RoomType;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sprint 13ter — Tests CRUD des types de chambre.
 *
 */
class RoomTypeControllerTest extends ApiTestCase
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
        $this->cleanupTestTypes();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanupTestTypes();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanupTestTypes(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        if ($tenant === null) {
            return;
        }
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                "DELETE FROM audit_logs WHERE entity_type = 'RoomType'"
            );
            $this->conn->executeStatement(
                "DELETE FROM room_types WHERE name LIKE 'TestType_%'"
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

    public function testManagerCanCRUDRoomTypes(): void
    {
        $token = $this->loginManager();

        $created = $this->apiRequest('POST', '/api/room-types', self::HOST,
            body: [
                'name'         => 'TestType_Premium',
                'baseRateXof'  => '120000.00',
                'maxOccupancy' => 4,
                'description'  => 'Test premium',
                'sortOrder'    => 99,
            ],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($created, 201);
        self::assertSame('TestType_Premium', $created['data']['name']);
        $id = $created['data']['id'];

        $updated = $this->apiRequest('PUT', "/api/room-types/$id", self::HOST,
            body: ['baseRateXof' => '130000.00'],
            headers: ['Authorization' => "Bearer $token"]);
        $this->assertApiSuccess($updated);
        self::assertSame('130000.00', $updated['data']['baseRateXof']);

        $this->apiRequest('DELETE', "/api/room-types/$id", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(204);
    }

    public function testReceptionistCannotWriteRoomTypes(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', self::HOST);

        $this->apiRequest('POST', '/api/room-types', self::HOST,
            body: ['name' => 'TestType_X', 'baseRateXof' => '10000.00', 'maxOccupancy' => 2],
            headers: ['Authorization' => "Bearer $token"]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDuplicateNameCaseInsensitiveRejected(): void
    {
        $token = $this->loginManager();

        $this->apiRequest('POST', '/api/room-types', self::HOST,
            body: ['name' => 'TestType_Unique', 'baseRateXof' => '20000.00', 'maxOccupancy' => 2],
            headers: ['Authorization' => "Bearer $token"]);
        self::assertResponseStatusCodeSame(201);

        // Même nom avec casse différente → conflit
        $this->apiRequest('POST', '/api/room-types', self::HOST,
            body: ['name' => 'testtype_unique', 'baseRateXof' => '20000.00', 'maxOccupancy' => 2],
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    public function testDeleteWithRoomsRejected(): void
    {
        $token = $this->loginManager();

        // Le type "Standard" de Savana est utilisé par des chambres en fixtures
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $type = $this->em->getRepository(RoomType::class)->findOneBy(['name' => 'Standard']);
            self::assertNotNull($type, 'Fixture savana doit avoir un type Standard');
            $typeId = (string) $type->getId();
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $response = $this->apiRequest('DELETE', "/api/room-types/$typeId", self::HOST,
            headers: ['Authorization' => "Bearer $token"]);

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString('utilisé', $response['error'] ?? '');
    }
}
