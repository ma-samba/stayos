<?php

declare(strict_types=1);

namespace App\Shared\Http\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Sprint 14-B.1.2.1 — Rate limiting global.
 *
 * Applique les rate limiters globaux à toutes les requêtes
 * entrantes selon le pattern de route et la méthode HTTP.
 *
 * Mapping route → limiter :
 * - /api/payments/paydunya/ipn      → webhooks (100/min/IP)
 * - /api/auth/send-otp              → otp_resend (3/10min/email)
 * - /api/auth/verify-otp            → otp_resend (3/10min/email)
 * - /api/* (GET/HEAD)               → api_read (300/min/IP)
 * - /api/* (POST/PUT/PATCH/DELETE)  → api_write (60/min/IP)
 * - /superadmin/*                   → api_write (60/min/IP) ou
 *                                     api_read selon la méthode
 *
 * Skips :
 * - /api/auth/login                 → géré par Symfony
 *                                     login_throttling
 * - /superadmin/auth/login          → idem
 * - /api/health                     → monitoring (UptimeRobot,
 *                                     Heroku healthcheck)
 * - Méthode OPTIONS                 → CORS preflight (nelmio_cors)
 *
 * Priority +20 (haute) pour s'exécuter AVANT le routage Symfony
 * — éviter de gaspiller du CPU sur un controller pour finalement
 * rejeter.
 *
 * Identification des clients :
 * - Par IP (Request::getClientIp()) pour la plupart — respecte
 *   les trusted_proxies depuis Sprint 14-B.1.2.1 (Heroku).
 * - Par email pour otp_resend (anti-spam ciblé sur les
 *   tentatives d'OTP), fallback IP si email absent.
 */
final class RateLimitSubscriber implements EventSubscriberInterface
{
    /**
     * Routes EXACTES où on skip ce subscriber.
     * Symfony login_throttling gère déjà les deux endpoints
     * de login. /api/health est sondé en continu par les
     * monitorings externes (UptimeRobot, Heroku healthcheck).
     */
    private const SKIP_EXACT_PATHS = [
        '/api/auth/login',
        '/superadmin/auth/login',
        '/api/health',
    ];

    /**
     * Routes du rate limiter `webhooks` (100/min/IP).
     */
    private const WEBHOOK_PATHS = [
        '/api/payments/paydunya/ipn',
    ];

    /**
     * Routes du rate limiter `otp_resend` (3/10min/email).
     */
    private const OTP_PATHS = [
        '/api/auth/send-otp',
        '/api/auth/verify-otp',
    ];

    public function __construct(
        private readonly RateLimiterFactory $apiReadLimiter,
        private readonly RateLimiterFactory $apiWriteLimiter,
        private readonly RateLimiterFactory $webhooksLimiter,
        private readonly RateLimiterFactory $otpResendLimiter,
        #[Target('business')]
        private readonly LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité +20 : avant FirewallListener (8) et
        // RouterListener (32) — on rejette tôt sans toucher
        // au controller.
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 20],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getPathInfo();
        $method  = $request->getMethod();

        // ── Skips ────────────────────────────────────────────
        // OPTIONS preflight CORS (géré par nelmio_cors)
        if ($method === 'OPTIONS') {
            return;
        }
        // Routes exactes à exclure (login_throttling, health)
        if (\in_array($path, self::SKIP_EXACT_PATHS, true)) {
            return;
        }

        // ── Sélection du limiter ─────────────────────────────
        $limiterInfo = $this->selectLimiter($path, $method, $request);
        if ($limiterInfo === null) {
            // Aucune règle ne matche — pas de rate limiting
            // (par ex. routes hors /api et hors /superadmin)
            return;
        }

        [$limiter, $identifier, $limiterName] = $limiterInfo;
        $rateLimit = $limiter->create($identifier)->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter    = $rateLimit->getRetryAfter();
            $secondsToWait = max(
                1,
                $retryAfter->getTimestamp() - time(),
            );

            $this->logger->warning('Rate limit exceeded', [
                'limiter'     => $limiterName,
                'identifier'  => $identifier,
                'path'        => $path,
                'method'      => $method,
                'retry_after' => $secondsToWait,
            ]);

            $response = new JsonResponse(
                [
                    'error'  => sprintf(
                        'Trop de requêtes. Réessayez dans %d secondes.',
                        $secondsToWait,
                    ),
                    'code'   => 'RATE_LIMITED',
                    'status' => 429,
                ],
                429,
            );
            $response->headers->set('Retry-After', (string) $secondsToWait);

            $event->setResponse($response);
        }
    }

    /**
     * Sélectionne le limiter et l'identifier à appliquer.
     *
     * @return array{0: RateLimiterFactory, 1: string, 2: string}|null
     */
    private function selectLimiter(
        string $path,
        string $method,
        Request $request,
    ): ?array {
        // ── Webhooks (priorité haute) ────────────────────────
        if (\in_array($path, self::WEBHOOK_PATHS, true)) {
            return [
                $this->webhooksLimiter,
                $request->getClientIp() ?? 'unknown',
                'webhooks',
            ];
        }

        // ── OTP send/verify ──────────────────────────────────
        if (\in_array($path, self::OTP_PATHS, true)) {
            // Identifier par email pour anti-spam ciblé,
            // fallback sur IP si email absent du body.
            $email = $this->extractEmail($request);

            return [
                $this->otpResendLimiter,
                $email ?? $request->getClientIp() ?? 'unknown',
                'otp_resend',
            ];
        }

        // ── /api/* + /superadmin/* ───────────────────────────
        if (
            \str_starts_with($path, '/api/')
            || \str_starts_with($path, '/superadmin/')
        ) {
            $identifier = $request->getClientIp() ?? 'unknown';

            if ($method === 'GET' || $method === 'HEAD') {
                return [$this->apiReadLimiter, $identifier, 'api_read'];
            }

            // POST, PUT, PATCH, DELETE
            return [$this->apiWriteLimiter, $identifier, 'api_write'];
        }

        return null;
    }

    /**
     * Extrait l'email d'une requête OTP (body JSON ou form).
     */
    private function extractEmail(Request $request): ?string
    {
        // Tenter JSON body en premier
        $content = $request->getContent();
        if (
            $content !== ''
            && \str_starts_with(
                (string) $request->headers->get('Content-Type'),
                'application/json',
            )
        ) {
            try {
                $data = \json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                if (\is_array($data) && isset($data['email'])) {
                    return strtolower(trim((string) $data['email']));
                }
            } catch (\JsonException) {
                // Pas du JSON valide — fallback sur le request
            }
        }

        // Fallback form-encoded
        $email = $request->request->get('email');
        if (\is_string($email)) {
            return strtolower(trim($email));
        }

        return null;
    }
}
