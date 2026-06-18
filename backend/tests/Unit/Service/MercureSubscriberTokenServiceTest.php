<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\Mercure\MercureSubscriberTokenService;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Sprint 14-B.2.1 — Vérifie la génération du JWT subscriber Mercure
 * scopé au tenant.
 */
class MercureSubscriberTokenServiceTest extends TestCase
{
    private const SECRET = 'test_mercure_secret_min_32_chars_long_xx';

    private function decode(string $jwt): Plain
    {
        $config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText(self::SECRET),
        );

        $token = $config->parser()->parse($jwt);
        self::assertInstanceOf(Plain::class, $token);

        return $token;
    }

    public function testGenerateTokenContainsTenantScopedSubscribeClaim(): void
    {
        $service = new MercureSubscriberTokenService(self::SECRET);

        $tenantId = Uuid::fromString('11111111-1111-1111-1111-111111111111');
        $tenant   = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($tenantId);

        $token   = $service->generateForTenant($tenant);
        $decoded = $this->decode($token);
        $mercure = $decoded->claims()->get('mercure');

        self::assertSame(
            ['/hotel/11111111-1111-1111-1111-111111111111/{event}'],
            $mercure['subscribe'],
        );

        // Pas de claim publish — subscriber uniquement.
        self::assertArrayNotHasKey('publish', $mercure);
    }

    public function testGenerateTokenHasOneHourExpiration(): void
    {
        $service = new MercureSubscriberTokenService(self::SECRET);

        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(Uuid::v4());

        $token   = $service->generateForTenant($tenant);
        $decoded = $this->decode($token);

        $exp = $decoded->claims()->get('exp');
        self::assertInstanceOf(\DateTimeImmutable::class, $exp);

        // Fenêtre [+3500s, +3700s] pour absorber la latence du test.
        $delta = $exp->getTimestamp() - time();
        self::assertGreaterThan(3500, $delta);
        self::assertLessThan(3700, $delta);
    }

    public function testGenerateTokenIsScopedPerTenant(): void
    {
        $service = new MercureSubscriberTokenService(self::SECRET);

        $tenantA = $this->createMock(Tenant::class);
        $tenantA->method('getId')->willReturn(
            Uuid::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'),
        );

        $tenantB = $this->createMock(Tenant::class);
        $tenantB->method('getId')->willReturn(
            Uuid::fromString('bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'),
        );

        $tokenA = $this->decode($service->generateForTenant($tenantA));
        $tokenB = $this->decode($service->generateForTenant($tenantB));

        self::assertSame(
            ['/hotel/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/{event}'],
            $tokenA->claims()->get('mercure')['subscribe'],
        );
        self::assertSame(
            ['/hotel/bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb/{event}'],
            $tokenB->claims()->get('mercure')['subscribe'],
        );
    }

    public function testGetTtlSecondsReturns3600(): void
    {
        $service = new MercureSubscriberTokenService(self::SECRET);
        self::assertSame(3600, $service->getTtlSeconds());
    }
}
