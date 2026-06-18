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

    /**
     * JWT capturé par loginAsManager()/login(), auto-injecté
     * dans les apiRequest() suivants si aucun header Authorization
     * n'est explicitement fourni. Permet d'enchaîner plusieurs
     * requêtes authentifiées sans re-passer le token (utile pour
     * les tests qui hammer un endpoint, ex : rate limiting).
     */
    protected ?string $authToken = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->authToken = null;

        // Sprint 14-B.1.2.1 — Reset le pool rate_limiter entre
        // tests. Le pool est filesystem en env test (configuré
        // pour que login_throttling persiste entre requêtes
        // d'un même test) — sans ce clear, les compteurs des
        // tests précédents accumulent et n'importe quel test
        // qui enchaîne >60 POST sur /api/* déclenche un 429
        // parasite. Login_throttling et tous nos limiters
        // partagent ce pool → un seul clear() suffit.
        static::getContainer()->get('cache.rate_limiter')->clear();
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

        // Auto-injection du JWT capturé par loginAsManager() si
        // l'appelant n'a pas déjà passé un header Authorization.
        if ($this->authToken !== null && !isset($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer ' . $this->authToken;
        }

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

    /**
     * Helper : login en MANAGER sur l'hôtel Savana (fixtures) et
     * stocke le JWT dans $this->authToken pour que les apiRequest
     * suivants soient authentifiés automatiquement.
     *
     * Retourne le token, utile pour les tests qui veulent le
     * combiner manuellement (overrides, hosts différents, etc.).
     */
    protected function loginAsManager(
        string $host = 'savana.localhost',
    ): string {
        $token = $this->login(
            'admin@savana-hotel.sn',
            'admin123',
            $host,
        );

        $this->authToken = $token;

        return $token;
    }
}
