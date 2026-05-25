<?php

namespace App\Tests\Functional\Api\Rate;

use App\Tests\Functional\ApiTestCase;

class PromotionControllerTest extends ApiTestCase
{
    // ── Routes — auth requise ──

    public function testGetPromotionsIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/promotions', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    // ── Tests avec fixtures ──

    /**
     * @group integration
     */
    public function testManagerCreatesPromotion(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'POST', '/api/promotions', 'savana.localhost',
            [
                'code'        => 'TESTPROMO50',
                'description' => 'Promo test 50%',
                'type'        => 'percentage',
                'value'       => '50.00',
                'minNights'   => 2,
                'isActive'    => true,
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
        self::assertEquals('TESTPROMO50', $response['data']['code']);
        self::assertEquals('percentage', $response['data']['type']);
    }

    /**
     * @group integration
     */
    public function testDuplicateActiveCodeRejected(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Create first promotion
        $this->apiRequest(
            'POST', '/api/promotions', 'savana.localhost',
            [
                'code'  => 'DUPETEST',
                'type'  => 'fixed',
                'value' => '5000.00',
            ],
            ['Authorization' => "Bearer $token"],
        );

        // Attempt duplicate — should be rejected
        $this->apiRequest(
            'POST', '/api/promotions', 'savana.localhost',
            [
                'code'  => 'DUPETEST',
                'type'  => 'fixed',
                'value' => '3000.00',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    /**
     * @group integration
     */
    public function testDeleteIsSoftDelete(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Create a promotion
        $createResponse = $this->apiRequest(
            'POST', '/api/promotions', 'savana.localhost',
            [
                'code'  => 'SOFTDEL',
                'type'  => 'percentage',
                'value' => '10.00',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($createResponse, 201);
        $promoId = $createResponse['data']['id'] ?? null;
        self::assertNotNull($promoId);

        // Delete (soft)
        $this->apiRequest(
            'DELETE', "/api/promotions/$promoId", 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(200);

        // Verify it still exists but is deactivated
        $showResponse = $this->apiRequest(
            'GET', "/api/promotions/$promoId", 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($showResponse);
        self::assertFalse($showResponse['data']['isActive']);
    }

    /**
     * @group integration
     */
    public function testHousekeeperForbidden(): void
    {
        $this->apiRequest('POST', '/api/auth/login', 'savana.localhost', [
            'email'    => 'housekeeper@savana-hotel.sn',
            'password' => 'house123',
        ]);

        $loginResponse = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $loginResponse['token'] ?? '';

        if ($token === '') {
            self::markTestSkipped('Pas de compte housekeeper en fixtures.');
        }

        $this->apiRequest(
            'GET', '/api/promotions', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(403);
    }

    // ── Validation — body incomplet ──

    /**
     * @group integration
     */
    public function testCreatePromotionWithMissingFieldsReturns422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Body vide — les champs obligatoires manquent → 422, pas 500
        $this->apiRequest(
            'POST', '/api/promotions', 'savana.localhost',
            [],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }
}
