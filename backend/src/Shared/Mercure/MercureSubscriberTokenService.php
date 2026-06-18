<?php

declare(strict_types=1);

namespace App\Shared\Mercure;

use App\Platform\Tenant\Domain\Entity\Tenant;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

/**
 * Génère des JWT subscriber Mercure scopés à un tenant.
 *
 * Le hub Mercure vérifie le claim `mercure.subscribe` pour autoriser
 * l'écoute de topics. En scopant à `/hotel/{tenantId}/{event}`, on
 * garantit l'isolation cross-tenant côté serveur Mercure (un JWT
 * Savana ne peut PAS écouter les topics Villa Collines).
 *
 * Sprint 14-B.2.1 — Préparation prod. Le hub reste anonymous en dev,
 * le JWT est posé en cookie pour vérification 14-C.
 *
 * Pas de claim `publish` → le subscriber ne peut QUE écouter. Le
 * publisher backend a son propre JWT séparé (mercure.yaml).
 */
final class MercureSubscriberTokenService
{
    /** TTL des tokens subscriber : 1h. */
    private const TOKEN_TTL_SECONDS = 3600;

    private readonly Configuration $jwtConfig;

    public function __construct(string $mercureJwtSecret)
    {
        $this->jwtConfig = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($mercureJwtSecret),
        );
    }

    /**
     * Génère un JWT subscriber autorisé à écouter UNIQUEMENT les topics
     * du tenant fourni.
     */
    public function generateForTenant(Tenant $tenant): string
    {
        $tenantId = (string) $tenant->getId();
        $now      = new \DateTimeImmutable();

        $token = $this->jwtConfig->builder()
            ->issuedAt($now)
            ->expiresAt($now->modify('+' . self::TOKEN_TTL_SECONDS . ' seconds'))
            ->withClaim('mercure', [
                'subscribe' => [
                    // URI Template RFC 6570 : {event} matche n'importe
                    // quel segment au-dessous de /hotel/{tenantId}/.
                    sprintf('/hotel/%s/{event}', $tenantId),
                ],
            ])
            ->getToken($this->jwtConfig->signer(), $this->jwtConfig->signingKey());

        return $token->toString();
    }

    public function getTtlSeconds(): int
    {
        return self::TOKEN_TTL_SECONDS;
    }
}
