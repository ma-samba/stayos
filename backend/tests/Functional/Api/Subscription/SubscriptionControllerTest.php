<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Subscription;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — SubscriptionController.
 *
 * RBAC : ROLE_MANAGER uniquement. Les endpoints lisent et écrivent
 * sur le schema public (subscriptions, plans, saas_invoices) — leur
 * isolation tenant repose sur le tenant courant du contexte (résolu
 * via le subdomain par TenantMiddleware).
 *
 * Nécessite les fixtures.
 *
 */
class SubscriptionControllerTest extends ApiTestCase
{
    public function testGetCurrentRequiresManager(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        $this->apiRequest(
            'GET',
            '/api/subscription',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    public function testHousekeeperGetsForbidden(): void
    {
        $token = $this->login('menage@savana-hotel.sn', 'menage123', 'savana.localhost');

        $this->apiRequest(
            'GET',
            '/api/subscription',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('ACCESS_DENIED', 403);
    }

    public function testManagerSeesOwnPlan(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/subscription',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);

        $data = $response['data'];
        self::assertSame('PRO', $data['plan']['name'], 'Savana est sur PRO en fixtures');
        self::assertSame('active', $data['status']);
        self::assertArrayHasKey('usage', $data);
        self::assertArrayHasKey('rooms', $data['usage']);
        self::assertArrayHasKey('users', $data['usage']);
    }

    public function testListPlansReturnsActivePlans(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/subscription/plans',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
        self::assertGreaterThanOrEqual(3, count($response['data']));

        $names = array_column($response['data'], 'name');
        self::assertContains('STARTER', $names);
        self::assertContains('PRO', $names);
        self::assertContains('ENTERPRISE', $names);
    }

    public function testUpgradeChangesPlan(): void
    {
        $token = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        /** @var EntityManagerInterface $em */
        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $proPlan = $em->getRepository(Plan::class)->findOneBy(['name' => 'PRO']);
        self::assertNotNull($proPlan);

        $response = $this->apiRequest(
            'POST',
            '/api/subscription/upgrade',
            'villa-collines.localhost',
            body: ['planId' => $proPlan->getId()],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertSame('PRO', $response['data']['plan']);

        // Remet villa-collines en STARTER pour ne pas polluer les fixtures.
        $starter = $em->getRepository(Plan::class)->findOneBy(['name' => 'STARTER']);
        $this->apiRequest(
            'POST',
            '/api/subscription/upgrade',
            'villa-collines.localhost',
            body: ['planId' => $starter->getId()],
            headers: ['Authorization' => "Bearer $token"],
        );
    }

    public function testUpgradeRejectsInvalidPlanId(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $this->apiRequest(
            'POST',
            '/api/subscription/upgrade',
            'savana.localhost',
            body: ['planId' => 999999],
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiError('NOT_FOUND', 404);
    }

    public function testCrossTenantIsolation(): void
    {
        // Manager Savana doit voir le plan PRO, manager Villa doit voir STARTER.
        $tokenSavana = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');
        $tokenVilla  = $this->login('admin@villa-collines.sn', 'admin123', 'villa-collines.localhost');

        $savana = $this->apiRequest(
            'GET',
            '/api/subscription',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $tokenSavana"],
        );
        $villa = $this->apiRequest(
            'GET',
            '/api/subscription',
            'villa-collines.localhost',
            headers: ['Authorization' => "Bearer $tokenVilla"],
        );

        self::assertSame('PRO',     $savana['data']['plan']['name']);
        self::assertSame('STARTER', $villa['data']['plan']['name']);
    }

    public function testInvoicesEndpointReturnsList(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest(
            'GET',
            '/api/subscription/invoices',
            'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response);
        self::assertIsArray($response['data']);
    }
}
