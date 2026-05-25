<?php

namespace App\Tests\Functional\Api\Dashboard;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels du DashboardController.
 *
 * Comptes utilisés (fixtures HotelDataFixtures) :
 *   Savana (Plan Pro — features: channel_manager, advanced_reports, revenue_management)
 *     - MANAGER:       admin@savana-hotel.sn      / admin123
 *     - RECEPTIONIST:  reception@savana-hotel.sn   / recep123
 *     - HOUSEKEEPER:   menage@savana-hotel.sn      / menage123
 *   Villa Collines (Plan Starter — features: [])
 *     - MANAGER:       admin@villa-collines.sn     / admin123
 *
 * @group integration
 */
class DashboardControllerTest extends ApiTestCase
{
    // ══════════════════════════════════════════════════════════
    //  GET /api/dashboard/today — RBAC
    // ══════════════════════════════════════════════════════════

    public function testTodayAccessibleToManager(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET', '/api/dashboard/today', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertArrayHasKey('occupancyRate', $response['data']);
        self::assertArrayHasKey('adrHt', $response['data']);
        self::assertArrayHasKey('revparHt', $response['data']);
        self::assertArrayHasKey('roomRevenueHt', $response['data']);
        self::assertArrayHasKey('arrivalsToday', $response['data']);
        self::assertArrayHasKey('departuresToday', $response['data']);
        self::assertArrayHasKey('occupiedRooms', $response['data']);
        self::assertArrayHasKey('availableRooms', $response['data']);
    }

    public function testTodayAccessibleToReceptionist(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET', '/api/dashboard/today', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertArrayHasKey('occupancyRate', $response['data']);
    }

    public function testTodayForbiddenForHousekeeper(): void
    {
        $token = $this->login('menage@savana-hotel.sn', 'menage123', 'savana.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/today', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    public function testTodayNotPubliclyAccessible(): void
    {
        $this->apiRequest('GET', '/api/dashboard/today', 'savana.localhost');

        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(200, $status);
        self::assertContains($status, [401, 403, 404]);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/dashboard/report — RBAC + feature flag
    // ══════════════════════════════════════════════════════════

    public function testReportAccessibleToManager(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET', '/api/dashboard/report?from=2026-05-01&to=2026-05-07', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertArrayHasKey('occupancyRate', $response['data']);
        self::assertArrayHasKey('dailySeries', $response['data']);
        self::assertCount(7, $response['data']['dailySeries']);
    }

    public function testReportForbiddenForReceptionist(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/report?from=2026-05-01&to=2026-05-07', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    public function testReportRequiresAdvancedReportsFeature(): void
    {
        // Villa Collines = plan Starter, pas de feature 'advanced_reports'
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/report?from=2026-05-01&to=2026-05-07', 'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        // 403 avec code PLAN_LIMIT (pas ACCESS_DENIED)
        $this->assertApiError('PLAN_LIMIT', 403);
    }

    // ══════════════════════════════════════════════════════════
    //  Validation des dates — /report
    // ══════════════════════════════════════════════════════════

    public function testReportInvalidDatesReturn422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // from > to
        $this->apiRequest(
            'GET', '/api/dashboard/report?from=2026-06-10&to=2026-06-01', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testReportMissingDatesReturn422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/report', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testReportPeriodTooLongReturns422(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // 367 jours (> 366 max)
        $this->apiRequest(
            'GET', '/api/dashboard/report?from=2025-01-01&to=2026-01-02', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    // ══════════════════════════════════════════════════════════
    //  GET /api/dashboard/report/export
    // ══════════════════════════════════════════════════════════

    public function testExportReturnsCsvFile(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/report/export?from=2026-05-01&to=2026-05-07&format=csv', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        self::assertResponseStatusCodeSame(200);

        $response = $this->client->getResponse();
        $disposition = $response->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('.csv', $disposition);
        self::assertStringContainsString('rapport-2026-05-01_2026-05-07', $disposition);

        // Vérifier que le contenu est du CSV valide (commence par BOM + en-tête)
        $content = $response->getContent();
        self::assertNotEmpty($content);
        self::assertStringContainsString('Rapport StayOS', $content);
        self::assertStringContainsString('Taux d\'occupation', $content);
    }

    public function testExportForbiddenForReceptionist(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'GET', '/api/dashboard/report/export?from=2026-05-01&to=2026-05-07', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }
}
