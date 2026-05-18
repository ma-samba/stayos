<?php

namespace App\Tests\Functional\Api;

use App\Tests\Functional\ApiTestCase;

class HealthControllerTest extends ApiTestCase
{
    public function testHealthReturns200WithOkStatus(): void
    {
        $response = $this->apiRequest('GET', '/api/health', 'localhost');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('ok', $response['status'] ?? null);
    }

    public function testHealthResponseContainsRequiredKeys(): void
    {
        $response = $this->apiRequest('GET', '/api/health', 'localhost');

        self::assertArrayHasKey('status', $response);
        self::assertArrayHasKey('checks', $response);
        self::assertArrayHasKey('version', $response);
    }

    public function testHealthChecksContainsDatabaseAndRedis(): void
    {
        $response = $this->apiRequest('GET', '/api/health', 'localhost');

        self::assertArrayHasKey('database', $response['checks'] ?? []);
        self::assertArrayHasKey('redis', $response['checks'] ?? []);
    }

    public function testHealthIsAccessibleWithoutAuthentication(): void
    {
        // La route /api/health est déclarée sans firewall JWT
        $this->client->request('GET', '/api/health');

        self::assertResponseStatusCodeSame(200);
    }
}
