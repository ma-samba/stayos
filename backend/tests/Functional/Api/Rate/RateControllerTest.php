<?php

namespace App\Tests\Functional\Api\Rate;

use App\Tests\Functional\ApiTestCase;

class RateControllerTest extends ApiTestCase
{
    // ── Routes — auth requise ──

    public function testGetPlansIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/rates/plans', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    public function testGetSeasonalIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/rates/seasonal', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    public function testPostQuoteIsNotPubliclyAccessible(): void
    {
        $this->apiRequest('POST', '/api/rates/quote', 'savana.localhost', [
            'roomId'   => '018e3f00-0000-7000-a000-000000000001',
            'checkIn'  => '2026-06-01',
            'checkOut' => '2026-06-04',
        ]);

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    // ── Tests avec fixtures ──

    /**
     * @group integration
     */
    public function testManagerCanListPlans(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET', '/api/rates/plans', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
    }

    /**
     * @group integration
     */
    public function testManagerCanCreatePlan(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'POST', '/api/rates/plans', 'savana.localhost',
            [
                'name'        => 'Tarif Weekend',
                'baseRateXof' => '55000.00',
                'minNights'   => 2,
                'isActive'    => true,
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
        self::assertEquals('Tarif Weekend', $response['data']['name']);
    }

    /**
     * @group integration
     */
    public function testCreateSeasonalRateInvalidDatesRejected(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $this->apiRequest(
            'POST', '/api/rates/seasonal', 'savana.localhost',
            [
                'name'      => 'Invalide',
                'type'      => 'multiplier',
                'value'     => '1.50',
                'startDate' => '2026-06-10',
                'endDate'   => '2026-06-05', // before start
                'priority'  => 5,
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    /**
     * @group integration
     */
    public function testQuoteReturnsPrice(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Get a real room ID from the fixtures
        $rooms = $this->apiRequest(
            'GET', '/api/rooms', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );
        $roomId = $rooms['data'][0]['id'] ?? null;
        self::assertNotNull($roomId, 'Au moins une chambre doit exister en fixtures');

        $response = $this->apiRequest(
            'POST', '/api/rates/quote', 'savana.localhost',
            [
                'roomId'   => $roomId,
                'checkIn'  => '2026-08-01',
                'checkOut' => '2026-08-04',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertArrayHasKey('totalXof', $response['data']);
        self::assertArrayHasKey('nights', $response['data']);
        self::assertEquals(3, $response['data']['nights']);
    }

    /**
     * @group integration
     */
    public function testQuoteWithPromoCodeDoesNotConsumePromo(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $rooms = $this->apiRequest(
            'GET', '/api/rooms', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );
        $roomId = $rooms['data'][0]['id'] ?? null;
        self::assertNotNull($roomId);

        // First quote with promo
        $response1 = $this->apiRequest(
            'POST', '/api/rates/quote', 'savana.localhost',
            [
                'roomId'    => $roomId,
                'checkIn'   => '2026-08-01',
                'checkOut'  => '2026-08-04',
                'promoCode' => 'OUVERTURE2026',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response1);

        // Second identical quote — if usedCount incremented, promo might exhaust
        $response2 = $this->apiRequest(
            'POST', '/api/rates/quote', 'savana.localhost',
            [
                'roomId'    => $roomId,
                'checkIn'   => '2026-08-01',
                'checkOut'  => '2026-08-04',
                'promoCode' => 'OUVERTURE2026',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response2);
        // Both quotes should return the same discount (promo not consumed)
        self::assertEquals($response1['data']['discountXof'], $response2['data']['discountXof']);
    }

    /**
     * @group integration
     */
    public function testHousekeeperCannotAccessRates(): void
    {
        // Login as a housekeeper (only has ROLE_ACCESS_HOUSEKEEPING)
        $this->apiRequest('POST', '/api/auth/login', 'savana.localhost', [
            'email'    => 'housekeeper@savana-hotel.sn',
            'password' => 'house123',
        ]);

        $loginResponse = json_decode((string) $this->client->getResponse()->getContent(), true);
        $token = $loginResponse['token'] ?? '';

        if ($token === '') {
            // If housekeeper account doesn't exist in fixtures, skip gracefully
            self::markTestSkipped('Pas de compte housekeeper en fixtures.');
        }

        $this->apiRequest(
            'GET', '/api/rates/plans', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @group integration
     */
    public function testCrossTenantIsolation(): void
    {
        // Login on Savana, create a plan
        $tokenSavana = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $savanaPlans = $this->apiRequest(
            'GET', '/api/rates/plans', 'savana.localhost',
            headers: ['Authorization' => "Bearer $tokenSavana"],
        );

        $this->assertApiSuccess($savanaPlans);

        // Login on Villa Collines — should see different (or no) plans
        $tokenVilla = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        if ($tokenVilla === '') {
            self::markTestSkipped('Pas de tenant Villa Collines en fixtures.');
        }

        $villaPlans = $this->apiRequest(
            'GET', '/api/rates/plans', 'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $tokenVilla"],
        );

        $this->assertApiSuccess($villaPlans);

        // Plans from different tenants must not leak
        $savanaIds = array_column($savanaPlans['data'] ?? [], 'id');
        $villaIds  = array_column($villaPlans['data'] ?? [], 'id');
        $overlap   = array_intersect($savanaIds, $villaIds);
        self::assertEmpty($overlap, 'Des tarifs du tenant Savana sont visibles depuis Villa Collines');
    }

    // ── Feature flag ──

    /**
     * @group integration
     */
    public function testStarterPlanCannotWriteRates(): void
    {
        // Villa Collines = plan STARTER (sans revenue_management).
        // Le user est MANAGER (passe le RBAC) mais le plan n'a pas
        // la feature → 403 FeatureNotAvailableException.
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        if ($token === '') {
            self::markTestSkipped('Pas de compte manager Villa Collines en fixtures.');
        }

        $this->apiRequest(
            'POST', '/api/rates/plans', 'villa-collines.localhost',
            [
                'name'        => 'Tarif interdit',
                'baseRateXof' => '40000.00',
            ],
            ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @group integration
     */
    public function testProPlanCanWriteRates(): void
    {
        // Savana = plan PRO (avec revenue_management) → écriture OK.
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'POST', '/api/rates/plans', 'savana.localhost',
            [
                'name'        => 'Tarif Pro autorisé',
                'baseRateXof' => '60000.00',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);
    }

    // ── Validation — body incomplet ──

    /**
     * @group integration
     */
    public function testCreatePlanWithMissingFieldsReturns422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Body vide — les champs obligatoires manquent → 422, pas 500
        $this->apiRequest(
            'POST', '/api/rates/plans', 'savana.localhost',
            [],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }
}
