<?php

declare(strict_types=1);

namespace App\Tests\Unit\Redis;

use App\Shared\Redis\RedisConnectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Tests UNITAIRES de parsing — pas de connexion Redis réelle.
 *
 * On ne teste que la méthode statique `parseUrl()` (pure : extrait
 * host/port/password/tls) et le fallback dev (qui exige la connexion
 * réelle pour tester de bout en bout — couvert par smoke test
 * fonctionnel ou en exécution réelle, pas ici).
 */
final class RedisConnectionFactoryTest extends TestCase
{
    public function testParsesHerokuRedissUrl(): void
    {
        // Format Heroku Data for Redis : rediss://:PASSWORD@HOST:PORT
        $parts = RedisConnectionFactory::parseUrl(
            'rediss://:p4ss-w0rd@ec2-1-2-3-4.compute-1.amazonaws.com:18712'
        );

        self::assertSame('ec2-1-2-3-4.compute-1.amazonaws.com', $parts['host']);
        self::assertSame(18712, $parts['port']);
        self::assertSame('p4ss-w0rd', $parts['password']);
        self::assertTrue($parts['tls']);
    }

    public function testParsesHerokuFormatWithUserPart(): void
    {
        // Forme Heroku historique où le user est `h` (ignoré, on
        // n'utilise QUE le password — pas d'ACL Redis 6 ici).
        $parts = RedisConnectionFactory::parseUrl(
            'rediss://h:secret@redis-1234.compute-1.amazonaws.com:18712'
        );

        self::assertSame('redis-1234.compute-1.amazonaws.com', $parts['host']);
        self::assertSame(18712, $parts['port']);
        self::assertSame('secret', $parts['password']);
        self::assertTrue($parts['tls']);
    }

    public function testParsesDevRedisUrl(): void
    {
        // Format dev : redis://host:port (pas de TLS, pas de password)
        $parts = RedisConnectionFactory::parseUrl('redis://redis:6379');

        self::assertSame('redis', $parts['host']);
        self::assertSame(6379, $parts['port']);
        self::assertNull($parts['password']);
        self::assertFalse($parts['tls']);
    }

    public function testParsesUrlEncodedPassword(): void
    {
        // Heroku peut générer des mots de passe avec des caractères
        // réservés (`@`, `:`, `+`, `/`, etc.) qui DOIVENT être
        // URL-encodés dans l'URL fournie. parse_url ne décode pas,
        // donc la factory urldecode() manuellement.
        $parts = RedisConnectionFactory::parseUrl(
            'rediss://:p%40ss%2Bword@host:6380'
        );

        self::assertSame('p@ss+word', $parts['password']);
    }

    public function testDefaultPortWhenAbsent(): void
    {
        $parts = RedisConnectionFactory::parseUrl('redis://localhost');

        self::assertSame('localhost', $parts['host']);
        self::assertSame(6379, $parts['port']);
        self::assertNull($parts['password']);
        self::assertFalse($parts['tls']);
    }

    public function testRejectsInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid REDIS_URL/');

        // parse_url accepte "not-a-url" et ne trouve pas de host.
        RedisConnectionFactory::parseUrl('not-a-url');
    }
}
