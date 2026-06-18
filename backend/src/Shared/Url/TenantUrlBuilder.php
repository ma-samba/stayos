<?php

declare(strict_types=1);

namespace App\Shared\Url;

/**
 * Préfixe l'hôte d'une URL avec un slug tenant.
 *
 *   http://localhost:5173  + villa-collines  → http://villa-collines.localhost:5173/...
 *   https://getstayos.com  + villa-collines  → https://villa-collines.getstayos.com/...
 *
 * Centralisé dans `Shared/Url` car utilisé par `SaasInvoiceService`
 * (Sprint 12) et `EmailService::sendStaffInvitation` (Sprint 13bis).
 */
final class TenantUrlBuilder
{
    public static function build(string $baseUrl, string $tenantSlug, string $path = ''): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host']   ?? 'localhost';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        return sprintf('%s://%s.%s%s%s', $scheme, $tenantSlug, $host, $port, $path);
    }
}
