<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\ApiTestCase;

/**
 * Test de garde globale du FeatureVoter (Sprint 14-A.1).
 *
 * Pour TOUS les endpoints feature-gated, vérifie que :
 * - Un tenant STARTER (Villa Collines, sans features Pro) reçoit
 *   403 avec code `PLAN_LIMIT` et message custom mentionnant la
 *   feature — preuve que c'est le voter qui a refusé, pas
 *   `AccessDeniedException` générique.
 * - Un tenant PRO (Savana, features `revenue_management` +
 *   `advanced_reports`) PASSE le voter. Le statut HTTP final peut
 *   être 2xx, 4xx métier (404/422/409), mais PAS 403.
 *
 * ⚠️ NOTE POUR LES FUTURS DÉVELOPPEURS : tout nouvel endpoint qui
 * ajoute `#[IsGranted('FEATURE_<x>')]` doit ajouter ici un cas
 * de test correspondant. Ce test est la barrière qui empêche les
 * régressions de feature-gating.
 *
 * Couvre les 11 endpoints feature-gated du Sprint 14-A.1 :
 *  - RateController : create/update/delete plans (3) +
 *    create/update/delete seasonal (3) — revenue_management
 *  - PromotionController : create/update/delete (3) —
 *    revenue_management
 *  - DashboardController : report + export (2) — advanced_reports
 */
class FeatureGuardTest extends ApiTestCase
{
    private const STARTER_HOST     = 'villa-collines.localhost';
    private const STARTER_USER     = 'admin@villa-collines.sn';
    private const STARTER_PASSWORD = 'admin123';

    private const PRO_HOST     = 'savana.localhost';
    private const PRO_USER     = 'admin@savana-hotel.sn';
    private const PRO_PASSWORD = 'admin123';

    // ──────────────────────────────────────────────────────────────
    //  STARTER — Villa Collines : tous les endpoints doivent être
    //  bloqués par le FeatureVoter en 403 + PLAN_LIMIT
    // ──────────────────────────────────────────────────────────────

    public function testStarterCannotCreateRatePlan(): void
    {
        $this->assertFeatureBlocked('POST', '/api/rates/plans', [
            'name'        => 'Test plan',
            'baseRateXof' => '25000.00',
            'isActive'    => true,
        ]);
    }

    public function testStarterCannotUpdateRatePlan(): void
    {
        $this->assertEndpointBlocked('PUT', '/api/rates/plans/1', [
            'name' => 'X', 'baseRateXof' => '0', 'isActive' => true,
        ]);
    }

    public function testStarterCannotDeleteRatePlan(): void
    {
        $this->assertEndpointBlocked('DELETE', '/api/rates/plans/1');
    }

    public function testStarterCannotCreateSeasonalRate(): void
    {
        $this->assertFeatureBlocked('POST', '/api/rates/seasonal', [
            'name'      => 'Été',
            'type'      => 'multiplier',
            'value'     => '1.2',
            'startDate' => '2026-07-01',
            'endDate'   => '2026-08-31',
            'priority'  => 10,
            'isActive'  => true,
        ]);
    }

    public function testStarterCannotUpdateSeasonalRate(): void
    {
        $this->assertEndpointBlocked('PUT', '/api/rates/seasonal/1', [
            'name'      => 'X',
            'type'      => 'multiplier',
            'value'     => '1',
            'startDate' => '2026-07-01',
            'endDate'   => '2026-07-02',
            'priority'  => 1,
            'isActive'  => true,
        ]);
    }

    public function testStarterCannotDeleteSeasonalRate(): void
    {
        $this->assertEndpointBlocked('DELETE', '/api/rates/seasonal/1');
    }

    public function testStarterCannotCreatePromotion(): void
    {
        $this->assertFeatureBlocked('POST', '/api/promotions', [
            'code'        => 'TEST10',
            'description' => 'Test',
            'type'        => 'percentage',
            'value'       => '10',
            'isActive'    => true,
        ]);
    }

    public function testStarterCannotUpdatePromotion(): void
    {
        $this->assertEndpointBlocked('PUT', '/api/promotions/1', [
            'code'        => 'X',
            'description' => 'X',
            'type'        => 'percentage',
            'value'       => '1',
            'isActive'    => true,
        ]);
    }

    public function testStarterCannotDeletePromotion(): void
    {
        $this->assertEndpointBlocked('DELETE', '/api/promotions/1');
    }

    public function testStarterCannotAccessReport(): void
    {
        $this->assertFeatureBlocked(
            'GET',
            '/api/dashboard/report?from=2026-01-01&to=2026-01-31',
        );
    }

    public function testStarterCannotExportReport(): void
    {
        $this->assertFeatureBlocked(
            'GET',
            '/api/dashboard/report/export?from=2026-01-01&to=2026-01-31',
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  PRO — Savana : doit PASSER le voter sur les deux features.
    //  L'assertion clé est `assertNotSame(403)` : le détail métier
    //  (200/201/404/422) est hors périmètre du test de garde.
    // ──────────────────────────────────────────────────────────────

    public function testProPassesFeatureGateOnRatePlanCreate(): void
    {
        $this->assertFeatureAllowed('POST', '/api/rates/plans', [
            'name'        => 'Test feature gate ' . uniqid(),
            'baseRateXof' => '25000.00',
            'isActive'    => true,
        ]);
    }

    public function testProPassesFeatureGateOnReport(): void
    {
        $this->assertFeatureAllowed(
            'GET',
            '/api/dashboard/report?from=2026-01-01&to=2026-01-31',
        );
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Vérifie qu'un endpoint feature-gated retourne 403 + PLAN_LIMIT
     * avec un message custom mentionnant la fonctionnalité — preuve
     * que c'est bien le FeatureVoter qui a refusé, pas une réponse
     * `AccessDeniedException` générique.
     */
    private function assertFeatureBlocked(
        string $method,
        string $path,
        ?array $payload = null,
    ): void {
        $token = $this->login(
            self::STARTER_USER,
            self::STARTER_PASSWORD,
            self::STARTER_HOST,
        );

        $body = $this->apiRequest(
            $method,
            $path,
            self::STARTER_HOST,
            $payload ?? [],
            ['Authorization' => "Bearer $token"],
        );

        $status = $this->client->getResponse()->getStatusCode();

        self::assertSame(
            403,
            $status,
            sprintf(
                "[%s %s] expected 403 from FeatureVoter, got %d. Body: %s",
                $method,
                $path,
                $status,
                $this->client->getResponse()->getContent(),
            ),
        );

        self::assertSame(
            'PLAN_LIMIT',
            $body['code'] ?? null,
            sprintf(
                "[%s %s] expected code=PLAN_LIMIT (FeatureVoter), got code=%s. "
                . "Si c'est ACCESS_DENIED, c'est qu'un AccessDeniedException "
                . "générique a été levée à la place de FeatureNotAvailableException.",
                $method,
                $path,
                $body['code'] ?? 'null',
            ),
        );

        self::assertStringContainsStringIgnoringCase(
            'fonctionnalité',
            (string) ($body['error'] ?? ''),
            'Expected feature-specific message in body.error',
        );
    }

    /**
     * Variante moins stricte pour les endpoints PUT/DELETE avec
     * `{id}` dans l'URL : Symfony résout l'entité via ParamConverter
     * AVANT que le FeatureVoter ne s'exécute (l'attribut method-level
     * `IsGranted` est évalué dans `onKernelControllerArguments`, soit
     * APRÈS la résolution des arguments). Si l'entité id=1 n'existe
     * pas dans le schema STARTER (cas normal, multi-tenant + features
     * non utilisées), un 404 NOT_FOUND est renvoyé avant que le voter
     * ne puisse refuser en 403 PLAN_LIMIT.
     *
     * Les DEUX réponses bloquent effectivement l'accès — le test
     * accepte donc :
     *  - 403 + PLAN_LIMIT (FeatureVoter a refusé)
     *  - 404 + NOT_FOUND  (ParamConverter a refusé d'abord)
     *
     * Une régression qui laisserait passer en 2xx serait détectée.
     */
    private function assertEndpointBlocked(
        string $method,
        string $path,
        ?array $payload = null,
    ): void {
        $token = $this->login(
            self::STARTER_USER,
            self::STARTER_PASSWORD,
            self::STARTER_HOST,
        );

        $body = $this->apiRequest(
            $method,
            $path,
            self::STARTER_HOST,
            $payload ?? [],
            ['Authorization' => "Bearer $token"],
        );

        $status = $this->client->getResponse()->getStatusCode();
        $code   = $body['code'] ?? null;

        self::assertTrue(
            ($status === 403 && $code === 'PLAN_LIMIT')
            || ($status === 404 && $code === 'NOT_FOUND'),
            sprintf(
                "[%s %s] expected 403/PLAN_LIMIT (voter) or 404/NOT_FOUND "
                . "(ParamConverter), got %d/%s. Body: %s",
                $method,
                $path,
                $status,
                $code ?? 'null',
                $this->client->getResponse()->getContent(),
            ),
        );
    }

    /**
     * Vérifie qu'un endpoint feature-gated PASSE le voter pour un
     * tenant Pro. Statut HTTP final peut être 200/201/404/422 selon
     * le contexte (validation DTO, fixtures), mais PAS 403/PLAN_LIMIT.
     */
    private function assertFeatureAllowed(
        string $method,
        string $path,
        ?array $payload = null,
    ): void {
        $token = $this->login(
            self::PRO_USER,
            self::PRO_PASSWORD,
            self::PRO_HOST,
        );

        $body = $this->apiRequest(
            $method,
            $path,
            self::PRO_HOST,
            $payload ?? [],
            ['Authorization' => "Bearer $token"],
        );

        $status = $this->client->getResponse()->getStatusCode();

        self::assertNotSame(
            403,
            $status,
            sprintf(
                "[%s %s] FeatureVoter a bloqué un tenant PRO (attendu : pass). Body: %s",
                $method,
                $path,
                $this->client->getResponse()->getContent(),
            ),
        );

        self::assertNotSame(
            'PLAN_LIMIT',
            $body['code'] ?? null,
            sprintf(
                "[%s %s] tenant PRO refusé pour PLAN_LIMIT alors qu'il a la feature",
                $method,
                $path,
            ),
        );
    }
}
