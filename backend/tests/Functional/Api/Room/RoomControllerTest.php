<?php

namespace App\Tests\Functional\Api\Room;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels — RoomController.
 *
 * Les tests @group integration nécessitent les fixtures chargées (make fixtures).
 * Les tests sans groupe tournent avec uniquement la BDD de test migrée.
 *
 * Lancer tous les tests : make test-functional
 * Lancer les tests d'intégration : php bin/phpunit --group integration
 */
class RoomControllerTest extends ApiTestCase
{
    // ──────────────────────────────────────────────────────────────
    //  Routes — existence et méthodes HTTP
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /api/rooms sans JWT doit retourner 401 (tenant trouvé) ou 404 (tenant absent).
     * Dans les deux cas, jamais de 200 (pas d'accès non authentifié).
     */
    public function testGetRoomsIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/rooms', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, 'La liste des chambres ne doit pas être accessible sans auth');
        self::assertContains($status, [401, 403, 404], "Statut inattendu : $status");
    }

    /**
     * GET /api/rooms/available sans JWT doit retourner 401 ou 404.
     */
    public function testGetAvailableRoomsIsNotPubliclyAccessible(): void
    {
        $this->apiRequest(
            'GET',
            '/api/rooms/available?from=2026-06-01&to=2026-06-05&adults=2',
            'savana.localhost',
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    /**
     * PATCH /api/rooms/{id}/status sans JWT doit retourner 401 ou 404.
     */
    public function testPatchRoomStatusIsNotPubliclyAccessible(): void
    {
        $fakeId = '018e3f00-0000-7000-a000-000000000001';

        $this->apiRequest(
            'PATCH',
            "/api/rooms/{$fakeId}/status",
            'savana.localhost',
            ['status' => 'cleaning'],
        );

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    /**
     * GET /api/rooms/{id} avec un UUID inexistant doit retourner 401 ou 404.
     */
    public function testGetRoomByIdIsNotPubliclyAccessible(): void
    {
        $fakeId = '018e3f00-0000-7000-a000-000000000001';

        $this->apiRequest('GET', "/api/rooms/{$fakeId}", 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Tests avec fixtures (nécessitent make fixtures)
    // ──────────────────────────────────────────────────────────────

    /**
     * @group integration
     */
    public function testGetRoomsRequiresAuthentication(): void
    {
        $this->apiRequest('GET', '/api/rooms', 'savana.localhost');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @group integration
     */
    public function testGetAvailableRoomsRequiresAuthentication(): void
    {
        $this->apiRequest(
            'GET',
            '/api/rooms/available?from=2026-06-01&to=2026-06-05&adults=2',
            'savana.localhost',
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @group integration
     */
    public function testGetAvailableRoomsWithInvalidDateReturnsBadRequest(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'GET',
            '/api/rooms/available?from=not-a-date&to=2026-06-05&adults=2',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    /**
     * @group integration
     */
    public function testGetAvailableRoomsWithCheckOutBeforeCheckInReturns422(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'GET',
            '/api/rooms/available?from=2026-06-10&to=2026-06-05&adults=2',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    /**
     * @group integration
     */
    public function testGetRoomsReturnsListWithExpectedStructure(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/rooms',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);

        if (!empty($response['data'])) {
            $room = $response['data'][0];
            self::assertArrayHasKey('number', $room);
            self::assertArrayHasKey('status', $room);
            self::assertArrayHasKey('type', $room);
        }
    }

    /**
     * @group integration
     */
    public function testUpdateRoomStatusWithInvalidValueReturns422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $rooms  = $this->apiRequest('GET', '/api/rooms', 'savana.localhost', headers: ['Authorization' => "Bearer $token"]);
        $roomId = $rooms['data'][0]['id'] ?? 'fake';

        $this->apiRequest(
            'PATCH',
            "/api/rooms/{$roomId}/status",
            'savana.localhost',
            ['status' => 'invalid_status'],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    /**
     * @group integration
     */
    public function testUpdateRoomStatusSuccess(): void
    {
        $token  = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');
        $rooms  = $this->apiRequest('GET', '/api/rooms', 'savana.localhost', headers: ['Authorization' => "Bearer $token"]);
        $roomId = $rooms['data'][0]['id'] ?? 'fake';

        $response = $this->apiRequest(
            'PATCH',
            "/api/rooms/{$roomId}/status",
            'savana.localhost',
            ['status' => 'cleaning'],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertSame('cleaning', $response['data']['status']);
    }
}
