<?php

declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Functional\ApiTestCase;

/**
 * Sprint 14-B.1.2.1 — Tests des rate limiters globaux
 * (RateLimitSubscriber).
 *
 * Pattern hérité du Sprint 14-A.3 C.1 :
 * - $client->disableReboot() pour partager le cache du limiter
 *   entre les requêtes du même test (sinon chaque request
 *   reboot le kernel, ce qui n'affecte pas le pool filesystem
 *   mais ralentit les tests).
 * - clear cache.rate_limiter en setUp pour isoler les tests
 *   les uns des autres (les compteurs filesystem survivent
 *   au reboot).
 *
 * Chaque test exerce UN limiter pour rester ciblé.
 */
class RateLimitTest extends ApiTestCase
{
    // Note : ApiTestCase::setUp() clear déjà le pool
    // cache.rate_limiter pour isoler chaque test.

    public function testApiReadLimiterAllowsNormalTraffic(): void
    {
        $this->loginAsManager();
        $this->client->disableReboot();

        // 5 requêtes GET d'affilée — bien en-dessous de la
        // limite (300/min). Toutes doivent passer.
        for ($i = 0; $i < 5; $i++) {
            $this->apiRequest(
                'GET',
                '/api/rooms',
                'savana.localhost',
            );
            self::assertNotSame(
                429,
                $this->client->getResponse()->getStatusCode(),
                "Iteration $i should not be rate-limited",
            );
        }
    }

    public function testApiWriteLimiterBlocksOnExcessive(): void
    {
        $this->loginAsManager();
        $this->client->disableReboot();

        // 62 requêtes POST au-dessus de la limite (60/min).
        // Au plus tard à la 61e, on attend un 429.
        $blocked = false;
        for ($i = 0; $i < 62; $i++) {
            $body = $this->apiRequest(
                'POST',
                '/api/floors',
                'savana.localhost',
                ['number' => 99 + $i, 'name' => "RateLimit$i"],
            );

            $statusCode = $this->client->getResponse()->getStatusCode();
            if ($statusCode === 429) {
                $blocked = true;
                self::assertSame('RATE_LIMITED', $body['code'] ?? null);
                self::assertNotNull(
                    $this->client->getResponse()
                        ->headers->get('Retry-After'),
                    'Retry-After header should be set on 429',
                );
                break;
            }
        }

        self::assertTrue(
            $blocked,
            'API write limiter should block within 62 POST requests (limit=60/min).',
        );
    }

    public function testHealthEndpointBypassesLimiter(): void
    {
        $this->client->disableReboot();

        // Hammer /api/health — UptimeRobot et Heroku peuvent
        // pinger très souvent. Ne doit JAMAIS être bloqué.
        for ($i = 0; $i < 100; $i++) {
            $this->apiRequest(
                'GET',
                '/api/health',
                'savana.localhost',
            );
            self::assertNotSame(
                429,
                $this->client->getResponse()->getStatusCode(),
                "Health endpoint should never be rate-limited (iteration $i)",
            );
        }
    }

    public function testOptionsPreflightBypassesLimiter(): void
    {
        $this->client->disableReboot();

        // OPTIONS = CORS preflight. Géré par nelmio_cors,
        // ne doit pas passer par RateLimitSubscriber. On hammer
        // au-dessus de la limite api_write (60/min) pour
        // s'assurer qu'aucun 429 ne sort.
        for ($i = 0; $i < 70; $i++) {
            $this->apiRequest(
                'OPTIONS',
                '/api/rooms',
                'savana.localhost',
                headers: [
                    'Origin'                        => 'http://savana.localhost:5173',
                    'Access-Control-Request-Method' => 'GET',
                ],
            );
            self::assertNotSame(
                429,
                $this->client->getResponse()->getStatusCode(),
                "OPTIONS preflight should never be rate-limited (iteration $i)",
            );
        }
    }

    public function testLoginThrottlingNotDoubleCounted(): void
    {
        $this->client->disableReboot();

        // Symfony login_throttling : 5 max → la 6e tentative
        // doit être 429 (Sprint 14-A.3 C.1). Ce test vérifie
        // que RateLimitSubscriber n'introduit PAS un double
        // rate limit qui déclencherait soit avant (par exemple
        // à la 4e via un autre limiter), soit après (à la 61e
        // via api_write — régression directe : login_throttling
        // ne fonctionnerait plus).
        $first429At = null;
        for ($i = 0; $i < 7; $i++) {
            $this->apiRequest(
                'POST',
                '/api/auth/login',
                'savana.localhost',
                ['email' => 'wrong@test.local', 'password' => 'wrong'],
            );
            if ($this->client->getResponse()->getStatusCode() === 429) {
                $first429At = $i;
                break;
            }
        }

        self::assertSame(
            5,
            $first429At,
            'Login 429 doit venir de login_throttling (après 5 tentatives), '
            . 'pas de api_write (qui serait à la 61e) ni d\'un autre limiter '
            . '(qui serait avant la 5e).',
        );
    }
}
