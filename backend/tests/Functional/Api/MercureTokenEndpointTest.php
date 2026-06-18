<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;

/**
 * Sprint 14-B.2.1 — Endpoint GET /api/mercure/token
 *
 * Couvre :
 * - Auth JWT staff requise
 * - Réponse JSON `{ token, ttlSeconds }`
 * - Cookie httpOnly `mercureAuthorization` posé avec le bon path
 * - Isolation cross-tenant : 2 tenants distincts obtiennent 2 tokens
 *   scopés à leur propre tenant uniquement
 */
class MercureTokenEndpointTest extends ApiTestCase
{
    private const SAVANA_HOST = 'savana.localhost';
    private const VILLA_HOST  = 'villa-collines.localhost';
    private const VILLA_USER  = 'admin@villa-collines.sn';
    private const VILLA_PWD   = 'admin123';

    private function decode(string $jwt): Plain
    {
        // Même secret que phpunit.xml.dist + .env
        $secret = $_SERVER['MERCURE_JWT_SECRET'] ?? $_ENV['MERCURE_JWT_SECRET'] ?? '';
        self::assertNotSame('', $secret, 'MERCURE_JWT_SECRET doit être défini en env test');

        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($secret),
        );
        $token = $config->parser()->parse($jwt);
        self::assertInstanceOf(Plain::class, $token);

        return $token;
    }

    public function testEndpointRequiresAuth(): void
    {
        $this->apiRequest('GET', '/api/mercure/token', self::SAVANA_HOST);

        self::assertResponseStatusCodeSame(401);
    }

    public function testEndpointReturnsTokenAndSetsCookie(): void
    {
        $this->loginAsManager(self::SAVANA_HOST);

        $body = $this->apiRequest('GET', '/api/mercure/token', self::SAVANA_HOST);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('token', $body['data']);
        self::assertArrayHasKey('ttlSeconds', $body['data']);
        self::assertSame(3600, $body['data']['ttlSeconds']);
        self::assertNotSame('', $body['data']['token']);

        // Cookie httpOnly + path scopé au hub
        $cookies = $this->client->getResponse()->headers->getCookies();
        $mercureCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'mercureAuthorization') {
                $mercureCookie = $cookie;
                break;
            }
        }
        self::assertNotNull($mercureCookie, 'Cookie mercureAuthorization doit être posé');
        self::assertTrue($mercureCookie->isHttpOnly());
        self::assertSame('/.well-known/mercure', $mercureCookie->getPath());
        self::assertSame($body['data']['token'], $mercureCookie->getValue());
        // En env test : secure=false (HTTP localhost), domain=null
        self::assertFalse($mercureCookie->isSecure());
        self::assertNull($mercureCookie->getDomain());
    }

    public function testTokenScopedToCurrentTenantOnly(): void
    {
        // Login Savana → token scopé Savana
        $this->loginAsManager(self::SAVANA_HOST);
        $bodySavana = $this->apiRequest('GET', '/api/mercure/token', self::SAVANA_HOST);
        $tokenSavana = $this->decode($bodySavana['data']['token']);
        $subSavana   = $tokenSavana->claims()->get('mercure')['subscribe'][0];

        // Login Villa → token scopé Villa
        $this->authToken = null;
        $villaToken = $this->login(self::VILLA_USER, self::VILLA_PWD, self::VILLA_HOST);
        $this->authToken = $villaToken;
        $bodyVilla = $this->apiRequest('GET', '/api/mercure/token', self::VILLA_HOST);
        $tokenVilla = $this->decode($bodyVilla['data']['token']);
        $subVilla   = $tokenVilla->claims()->get('mercure')['subscribe'][0];

        // Les deux topics scopent à des tenants différents
        self::assertNotSame($subSavana, $subVilla);
        self::assertMatchesRegularExpression(
            '#^/hotel/[0-9a-f-]+/\{event\}$#',
            $subSavana,
        );
        self::assertMatchesRegularExpression(
            '#^/hotel/[0-9a-f-]+/\{event\}$#',
            $subVilla,
        );
    }
}
