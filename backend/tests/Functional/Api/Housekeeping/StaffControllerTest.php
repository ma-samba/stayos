<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Housekeeping;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels — StaffController.
 *
 * Endpoint utilisé par le sélecteur d'assignation du module Housekeeping
 * pour lister les housekeepers du tenant courant. RBAC : MANAGER +
 * RECEPTIONIST autorisés, HOUSEKEEPER refusé. Isolation tenant garantie
 * par le search_path PostgreSQL — vérifié via les fixtures Villa Collines
 * qui n'ont aucun housekeeper.
 *
 * Lancer : php bin/phpunit --group integration
 */
class StaffControllerTest extends ApiTestCase
{
    // ──────────────────────────────────────────────────────────────
    //  Routes — accessibilité publique
    // ──────────────────────────────────────────────────────────────

    public function testGetStaffIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/staff', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status, 'La liste du staff ne doit pas être accessible sans auth');
        self::assertContains($status, [401, 403, 404], "Statut inattendu : $status");
    }

    // ──────────────────────────────────────────────────────────────
    //  Tests avec fixtures (nécessitent make fixtures)
    // ──────────────────────────────────────────────────────────────

    /**
     */
    public function testManagerCanListHousekeepers(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/staff?role=ROLE_HOUSEKEEPER',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
        self::assertNotEmpty($response['data'], 'Savana doit avoir au moins un housekeeper');

        $hk = $response['data'][0];
        self::assertArrayHasKey('id', $hk);
        self::assertArrayHasKey('fullName', $hk);
        self::assertArrayHasKey('email', $hk);
        self::assertArrayHasKey('roles', $hk);
        self::assertContains('ROLE_HOUSEKEEPER', $hk['roles']);
        // Aucun champ sensible ne doit fuiter
        self::assertArrayNotHasKey('password', $hk);
    }

    /**
     */
    public function testReceptionistCanListHousekeepers(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/staff?role=ROLE_HOUSEKEEPER',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
    }

    /**
     */
    public function testHousekeeperIsDeniedAccess(): void
    {
        $token = $this->login('menage@savana-hotel.sn', 'menage123', 'savana.localhost');

        $this->apiRequest(
            'GET',
            '/api/staff?role=ROLE_HOUSEKEEPER',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    /**
     */
    public function testListWithoutRoleFilterReturnsAllStaff(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/staff',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
        // Au moins le manager qui fait la requête doit apparaître
        self::assertGreaterThanOrEqual(1, count($response['data']));
    }

    /**
     * Isolation tenant : Villa Collines n'a aucun housekeeper en fixtures,
     * un manager Villa ne doit donc PAS voir les housekeepers Savana.
     *
     */
    public function testTenantIsolation(): void
    {
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/staff?role=ROLE_HOUSEKEEPER',
            'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertSame(
            [],
            $response['data'],
            "Villa Collines ne doit pas voir les housekeepers de Savana (search_path tenant)",
        );
    }
}
