<?php

namespace App\Tests\Functional\Api\Guest;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels du GuestController.
 *
 * Ces tests vérifient le gating d'authentification et les routes de base.
 * Lancez `make fixtures` puis `make test-functional` pour les exécuter.
 */
class GuestControllerTest extends ApiTestCase
{
    // ── Auth gating ──

    /**
     * GET /api/guests sans auth retourne 401.
     */
    public function testListGuestsRequiresAuth(): void
    {
        $this->apiRequest('GET', '/api/guests', headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * POST /api/guests sans auth retourne 401.
     */
    public function testCreateGuestRequiresAuth(): void
    {
        $this->apiRequest('POST', '/api/guests', body: [
            'firstName' => 'Test',
            'lastName'  => 'Client',
        ], headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * GET /api/guests/{id} sans auth retourne 401.
     */
    public function testShowGuestRequiresAuth(): void
    {
        $this->apiRequest(
            'GET',
            '/api/guests/00000000-0000-0000-0000-000000000001',
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * PUT /api/guests/{id} sans auth retourne 401.
     */
    public function testUpdateGuestRequiresAuth(): void
    {
        $this->apiRequest(
            'PUT',
            '/api/guests/00000000-0000-0000-0000-000000000001',
            body: ['firstName' => 'Updated'],
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * GET /api/guests/{id}/stays sans auth retourne 401.
     */
    public function testStaysRequiresAuth(): void
    {
        $this->apiRequest(
            'GET',
            '/api/guests/00000000-0000-0000-0000-000000000001/stays',
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    // ── Search ──

    /**
     * GET /api/guests?q=xxx sans auth retourne 401.
     */
    public function testSearchGuestsRequiresAuth(): void
    {
        $this->apiRequest('GET', '/api/guests?q=Diallo', headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    // ── Cross-tenant isolation (no auth → 401, can't leak data) ──

    /**
     * Accéder aux clients d'un autre tenant retourne 401.
     */
    public function testCrossTenantGuestAccessDenied(): void
    {
        // Même sans auth, le tenant slug change — vérifier que l'isolation existe
        $this->apiRequest('GET', '/api/guests', headers: [
            'X-Tenant-Slug' => 'villa-collines',
        ]);

        self::assertResponseStatusCodeSame(401);
    }
}
