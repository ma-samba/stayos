<?php

namespace App\Tests\Functional\Security;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests d'isolation multi-tenant — PRIORITÉ ABSOLUE.
 *
 * Ces tests vérifient que :
 * 1. Un subdomain inconnu retourne 404 (pas d'info sur l'existence)
 * 2. Les routes exclues (/api/health) bypasse la résolution tenant
 * 3. Le middleware TenantMiddleware s'applique correctement
 *
 * @see \App\Platform\Tenant\Infrastructure\Middleware\TenantMiddleware
 */
class MultiTenantIsolationTest extends ApiTestCase
{
    /**
     * Un subdomain inconnu doit retourner 404.
     * Ne pas révéler si le tenant existe (éviter l'énumération).
     */
    public function testUnknownSubdomainReturns404(): void
    {
        $this->client->request('GET', '/api/rooms', server: [
            'HTTP_HOST' => 'hotel-inconnu-xyz-99999.localhost',
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * /api/health doit être accessible même sans subdomain tenant valide.
     */
    public function testHealthBypassesTenantMiddleware(): void
    {
        $this->client->request('GET', '/api/health', server: [
            'HTTP_HOST' => 'localhost',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * /api/health doit bypasser la résolution tenant même sur un subdomain inconnu.
     * Cela permet à UptimeRobot de monitorer sans subdomain valide.
     */
    public function testHealthIsPublicOnAnySubdomain(): void
    {
        $this->client->request('GET', '/api/health', server: [
            'HTTP_HOST' => 'monitoring.localhost',
        ]);

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Un host sans subdomain (ex: localhost seul) ne doit pas déclencher
     * la résolution tenant — le middleware doit simplement passer.
     */
    public function testSinglePartHostSkipsTenantResolution(): void
    {
        // Sur une route protégée avec un host sans subdomain,
        // le middleware passe (count(parts) < 2), puis le firewall JWT
        // retourne 401 (pas d'authentification) — pas 404.
        $this->client->request('GET', '/api/rooms', server: [
            'HTTP_HOST' => 'localhost',
        ]);

        // 401 ou 403 (JWT manquant) — mais PAS 404 (pas d'erreur tenant)
        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [401, 403],
        );
    }
}
