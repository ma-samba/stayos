<?php

declare(strict_types=1);

namespace App\Shared\Http\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute les headers HTTP de sécurité à toutes les réponses
 * du backend StayOS.
 *
 * Stratégie : passifs, configurables par environnement.
 * - X-Content-Type-Options: nosniff (anti-MIME-sniffing)
 * - X-Frame-Options: DENY (anti-clickjacking — le backend ne
 *   sert jamais de HTML embed légitimement)
 * - Referrer-Policy: strict-origin-when-cross-origin
 * - Permissions-Policy: désactive les API browser non
 *   utilisées (géoloc, micro, caméra, paiement)
 * - HSTS: activé UNIQUEMENT en prod (éviter de bloquer le
 *   dev local en HTTP). max-age=31536000 (1 an),
 *   includeSubDomains.
 * - Content-Security-Policy: ULTRA-STRICT car le backend ne
 *   sert que de l'API JSON (pas de HTML user-facing).
 *   `default-src 'none'` interdit tout chargement.
 *
 * ⚠️ Le CSP du FRONTEND (Vercel) est complètement différent
 * et sera configuré au Sprint 14-C via les headers Vercel.
 * Ici on parle UNIQUEMENT du backend.
 *
 * Décisions configurables par env (services.yaml) :
 * - `security.headers.hsts_enabled` : false en dev/test,
 *   true en prod
 * - `security.headers.csp_enabled` : true partout
 *   (modifiable pour debug)
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly bool $hstsEnabled,
        private readonly bool $cspEnabled,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // Priorité basse pour s'exécuter après tous les autres
        // listeners qui modifient la response (CORS, etc.)
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Ne pas ajouter les headers aux sub-requests (forward,
        // ESI, etc.) — uniquement aux réponses HTTP réelles.
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        // ── Headers passifs (toujours activés) ──────────────
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
        );

        // ── HSTS (prod uniquement) ──────────────────────────
        // Préserve HTTPS pour 1 an, inclut les sous-domaines
        // (api.getstayos.com, mercure.getstayos.com, etc.)
        if ($this->hstsEnabled) {
            $headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        // ── CSP backend (ultra-strict) ──────────────────────
        // L'API ne sert QUE du JSON. Aucun HTML, aucun JS,
        // aucun CSS. `default-src 'none'` est la politique
        // la plus restrictive possible. `frame-ancestors 'none'`
        // double l'effet de X-Frame-Options sur les navigateurs
        // modernes.
        if ($this->cspEnabled) {
            $headers->set(
                'Content-Security-Policy',
                "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
            );
        }
    }
}
