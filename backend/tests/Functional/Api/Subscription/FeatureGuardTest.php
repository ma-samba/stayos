<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Subscription;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels — FeatureChecker / feature-gating sur les
 * endpoints réels.
 *
 * Vérifie que :
 *  - un tenant STARTER n'a pas accès aux endpoints Pro
 *    (advanced_reports, revenue_management).
 *  - un tenant PRO accède aux mêmes endpoints sans erreur.
 *
 * Endpoints choisis :
 *  - GET /api/dashboard/report → feature 'advanced_reports' (PRO+)
 *  - GET /api/promotions       → feature 'revenue_management' (PRO+)
 *
 * @group integration
 */
class FeatureGuardTest extends ApiTestCase
{
    public function testStarterCannotAccessAdvancedReports(): void
    {
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        $this->apiRequest(
            'GET',
            '/api/dashboard/report?from=2026-06-01&to=2026-06-30',
            'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('PLAN_LIMIT', 403);
    }

    public function testStarterCannotAccessRevenueManagement(): void
    {
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        // GET /api/promotions est ouvert ; la feature ne gate que les
        // mutations. On tente une création pour vérifier le plan-limit.
        $this->apiRequest(
            'POST',
            '/api/promotions',
            'villa-collines.localhost',
            body: [
                'code'          => 'TEST',
                'description'   => 'Test',
                'discountType'  => 'percentage',
                'discountValue' => 10,
                'validFrom'     => '2026-06-01',
                'validTo'       => '2026-06-30',
            ],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('PLAN_LIMIT', 403);
    }

    public function testProCanAccessAdvancedReports(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/dashboard/report?from=2026-06-01&to=2026-06-30',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        // PRO autorise la feature — on ne s'attend PAS à un 403.
        $status = $this->client->getResponse()->getStatusCode();
        self::assertNotSame(403, $status, 'PRO doit accéder à advanced_reports');
        self::assertNotEmpty($response);
    }

    public function testProCanAccessRevenueManagement(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // On ne valide pas la création complète (qui exigerait des données
        // métier valides) : seul l'absence de 403 PLAN_LIMIT compte.
        $this->apiRequest(
            'POST',
            '/api/promotions',
            'savana.localhost',
            body: [], // payload vide → 422 attendu, jamais 403 PLAN_LIMIT
            headers: ['Authorization' => "Bearer $token"],
        );

        $status   = $this->client->getResponse()->getStatusCode();
        $response = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];

        self::assertNotSame(403, $status, 'PRO ne doit pas se voir refuser pour PLAN_LIMIT');
        self::assertNotSame(
            'PLAN_LIMIT',
            $response['code'] ?? null,
            'PRO inclut revenue_management dans ses features',
        );
    }
}
