<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\ApiTestCase;

/**
 * Vérifie que les headers de sécurité sont présents sur les
 * réponses HTTP du backend. La configuration HSTS étant
 * env-dependent, on teste sur la valeur effective de l'env
 * test (HSTS désactivé, cohérent avec dev).
 */
class SecurityHeadersTest extends ApiTestCase
{
    public function testHealthEndpointHasSecurityHeaders(): void
    {
        $this->apiRequest('GET', '/api/health', 'savana.localhost');

        $response = $this->client->getResponse();
        $headers  = $response->headers;

        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame(
            'strict-origin-when-cross-origin',
            $headers->get('Referrer-Policy'),
        );
        self::assertStringContainsString(
            'geolocation=()',
            (string) $headers->get('Permissions-Policy'),
        );
        self::assertStringContainsString(
            "default-src 'none'",
            (string) $headers->get('Content-Security-Policy'),
        );

        // HSTS désactivé en env test (cohérent avec dev)
        self::assertNull($headers->get('Strict-Transport-Security'));
    }

    public function testLoginEndpointHasSecurityHeaders(): void
    {
        // Vérifier que les headers sont AUSSI sur les endpoints
        // qui passent par Lexik (auth failure handler).
        $this->apiRequest(
            'POST',
            '/api/auth/login',
            'savana.localhost',
            ['email' => 'fake@nowhere.local', 'password' => 'wrong'],
        );

        $response = $this->client->getResponse();
        self::assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
        );
    }

    public function testErrorResponseHasSecurityHeaders(): void
    {
        // Une 404 doit aussi avoir les headers de sécurité —
        // worst-case (page d'erreur Symfony).
        $this->apiRequest(
            'GET',
            '/api/nonexistent-route',
            'savana.localhost',
        );

        $response = $this->client->getResponse();
        self::assertSame(404, $response->getStatusCode());
        self::assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
        );
    }
}
