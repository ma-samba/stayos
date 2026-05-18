<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Classe de base pour tous les tests fonctionnels API.
 *
 * Fournit des helpers pour effectuer des requêtes HTTP JSON
 * et des assertions sur les réponses standardisées.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /**
     * Effectue une requête JSON vers l'API et retourne le corps décodé.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function apiRequest(
        string $method,
        string $url,
        string $host = 'localhost',
        array  $body = [],
        array  $headers = [],
    ): array {
        $server = [
            'HTTP_HOST'    => $host,
            'CONTENT_TYPE' => 'application/json',
        ];

        foreach ($headers as $key => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $key))] = $value;
        }

        $this->client->request(
            method:  $method,
            uri:     $url,
            server:  $server,
            content: $body !== [] ? json_encode($body) : null,
        );

        $content = (string) $this->client->getResponse()->getContent();

        return json_decode($content, true) ?? [];
    }

    /**
     * Asserte qu'une réponse est un succès (status 200, clé "data" présente).
     *
     * @param array<string, mixed> $response
     */
    protected function assertApiSuccess(array $response, int $status = 200): void
    {
        self::assertResponseStatusCodeSame($status);
        self::assertArrayHasKey('data', $response);
        self::assertEquals($status, $response['status'] ?? $status);
    }

    /**
     * Asserte qu'une réponse est une erreur avec le bon code métier.
     */
    protected function assertApiError(string $expectedCode, int $expectedStatus): void
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        $response = json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
        );
        self::assertEquals($expectedCode, $response['code'] ?? null);
    }

    /**
     * Simule un login et retourne le JWT.
     * Utilisé dans les sprints suivants (auth JWT).
     */
    protected function login(
        string $email,
        string $password,
        string $host = 'savana.localhost',
    ): string {
        $response = $this->apiRequest(
            'POST',
            '/api/auth/login',
            $host,
            ['email' => $email, 'password' => $password],
        );

        return $response['token'] ?? '';
    }
}
