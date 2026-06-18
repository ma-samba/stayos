<?php

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/health — Endpoint de monitoring (pas de firewall JWT).
 * Vérifié par UptimeRobot toutes les 5 minutes en production.
 */
class HealthController extends AbstractController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks  = [];
        $healthy = true;

        // ── PostgreSQL ────────────────────────────────────────────────────────
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $this->logger->error('Health check: database probe failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $checks['database'] = 'error';
            $healthy = false;
        }

        // ── Redis ─────────────────────────────────────────────────────────────
        try {
            $checks['redis'] = $this->pingRedis() ? 'ok' : 'error';
        } catch (\Throwable $e) {
            $this->logger->error('Health check: redis probe failed', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            $checks['redis'] = 'error';
        }

        if ('error' === $checks['redis']) {
            $healthy = false;
        }

        return new JsonResponse([
            'status'  => $healthy ? 'ok' : 'error',
            'checks'  => $checks,
            'version' => $_ENV['APP_VERSION'] ?? '1.0.0',
        ], $healthy ? 200 : 503);
    }

    private function pingRedis(): bool
    {
        $result = $this->redis->ping();
        return true === $result || str_contains((string) $result, 'PONG');
    }
}
