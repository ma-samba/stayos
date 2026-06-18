<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Shared\Mercure\MercureSubscriberTokenService;
use App\Shared\TenantContext;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Endpoint qui génère un JWT subscriber Mercure et le pose en cookie
 * httpOnly.
 *
 * Sprint 14-B.2.1 — Préparation prod. Le hub Mercure reste anonymous
 * en dev (donc le cookie n'est pas vérifié), mais le pipeline est
 * fonctionnellement identique : au Sprint 14-C on bascule le hub en
 * `anonymous=false` côté Heroku Config Vars sans rien changer ici.
 *
 * Sécurité :
 * - Auth JWT staff requise (firewall `api`)
 * - Cookie `httpOnly` (inaccessible au JS — anti-XSS exfiltration)
 * - Cookie `secure` (HTTPS only en prod)
 * - Cookie `samesite=lax` (envoyé sur navigation cross-origin)
 * - Cookie scope `/.well-known/mercure` (envoyé uniquement au hub)
 *
 * Réponse :
 * - JSON `{ data: { token, ttlSeconds }, ... }` (debug + refresh
 *   anticipé côté client)
 * - Cookie `mercureAuthorization` (pattern Mercure standard)
 */
class MercureTokenController extends AbstractApiController
{
    public function __construct(
        private readonly MercureSubscriberTokenService $tokenService,
        private readonly TenantContext $tenantContext,
        private readonly bool $cookieSecure,
        private readonly string $cookieDomain,
    ) {}

    #[Route('/api/mercure/token', name: 'api_mercure_token', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(): JsonResponse
    {
        $tenant = $this->tenantContext->get();
        $token  = $this->tokenService->generateForTenant($tenant);
        $ttl    = $this->tokenService->getTtlSeconds();

        $response = $this->jsonSuccess([
            'token'      => $token,
            'ttlSeconds' => $ttl,
        ]);

        $response->headers->setCookie(
            Cookie::create('mercureAuthorization')
                ->withValue($token)
                ->withExpires(time() + $ttl)
                ->withPath('/.well-known/mercure')
                ->withDomain($this->cookieDomain !== '' ? $this->cookieDomain : null)
                ->withSecure($this->cookieSecure)
                ->withHttpOnly(true)
                ->withSameSite(Cookie::SAMESITE_LAX),
        );

        return $response;
    }
}
