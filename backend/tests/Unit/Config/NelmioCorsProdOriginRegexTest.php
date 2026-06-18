<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Sprint 14-C — Vérifie la regex d'origine CORS posée dans
 * `when@prod` de `config/packages/nelmio_cors.yaml` pour autoriser
 * les sous-domaines tenants *.getstayos.com (front multi-subdomain).
 *
 * Approche : on extrait la regex directement du YAML pour rester en
 * synchro, puis on la teste avec preg_match en reproduisant
 * exactement ce que fait nelmio_cors (cf. CorsListener::checkOrigin :
 * `preg_match('{'.$originRegexp.'}i', $origin)`).
 *
 * On garde un test unitaire plutôt qu'un test fonctionnel parce que
 * le bloc `when@prod` n'est pas actif en env test ; le fonctionnel
 * tomberait dans `when@test` et ne couvrirait pas la règle prod.
 */
class NelmioCorsProdOriginRegexTest extends TestCase
{
    private const TENANT_SUBDOMAIN_REGEX = '^https://[a-z0-9-]+\.getstayos\.com$';

    private static function nelmioMatch(string $regex, string $origin): bool
    {
        return 1 === preg_match('{'.$regex.'}i', $origin);
    }

    public function testRegexIsListedOnApiAndPublicPathsInProd(): void
    {
        $config = Yaml::parseFile(
            __DIR__.'/../../../config/packages/nelmio_cors.yaml',
            Yaml::PARSE_CUSTOM_TAGS,
        );

        $prodApi = $config['when@prod']['nelmio_cors']['paths']['^/api']['allow_origin'] ?? [];
        $prodPub = $config['when@prod']['nelmio_cors']['paths']['^/public']['allow_origin'] ?? [];

        self::assertContains(
            self::TENANT_SUBDOMAIN_REGEX,
            $prodApi,
            'La regex *.getstayos.com doit être listée sous when@prod paths ^/api.',
        );
        self::assertContains(
            self::TENANT_SUBDOMAIN_REGEX,
            $prodPub,
            'La regex *.getstayos.com doit être listée sous when@prod paths ^/public.',
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allowedOriginsProvider(): array
    {
        return [
            'tenant slug court'          => ['https://savana.getstayos.com'],
            'tenant slug avec tiret'     => ['https://villa-collines.getstayos.com'],
            'demo (couvert par regex)'   => ['https://demo.getstayos.com'],
            'slug avec chiffres'         => ['https://hotel42.getstayos.com'],
        ];
    }

    /**
     * @dataProvider allowedOriginsProvider
     */
    public function testRegexMatchesValidTenantSubdomain(string $origin): void
    {
        self::assertTrue(
            self::nelmioMatch(self::TENANT_SUBDOMAIN_REGEX, $origin),
            "L'Origin '$origin' devrait être autorisée par la regex *.getstayos.com.",
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedOriginsProvider(): array
    {
        return [
            'tld différent'              => ['https://savana.evil.com'],
            'suffix injection'           => ['https://savana.getstayos.com.evil.com'],
            'sous-sous-domaine'          => ['https://api.savana.getstayos.com'],
            'http sans TLS'              => ['http://savana.getstayos.com'],
            'underscore dans le slug'    => ['https://savana_hotel.getstayos.com'],
            'caractères non ascii'       => ['https://savaná.getstayos.com'],
            'apex sans slug'             => ['https://.getstayos.com'],
            'apex sans sous-domaine'     => ['https://getstayos.com'],
            'similaire mais autre TLD'   => ['https://savana.getstayos.co'],
        ];
    }

    /**
     * @dataProvider rejectedOriginsProvider
     */
    public function testRegexRejectsUnauthorizedOrigin(string $origin): void
    {
        self::assertFalse(
            self::nelmioMatch(self::TENANT_SUBDOMAIN_REGEX, $origin),
            "L'Origin '$origin' NE devrait PAS être autorisée par la regex *.getstayos.com.",
        );
    }
}
