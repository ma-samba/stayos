<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\TenantContext;
use PHPUnit\Framework\TestCase;

class BusinessDateServiceTest extends TestCase
{
    private function makeService(int $cutoffHour, string $timezone = 'Africa/Dakar'): BusinessDateService
    {
        $tenant = new Tenant();
        $tenant->setTimezone($timezone);
        $tenant->setBusinessDayCutoffHour($cutoffHour);

        $context = new TenantContext();
        $context->set($tenant);

        return new BusinessDateService($context);
    }

    public function testToBusinessDateBeforeCutoffReturnsYesterday(): void
    {
        $service = $this->makeService(5);

        // 03:00 le 10/06 dans Africa/Dakar → business date = 09/06
        $instant = new \DateTimeImmutable('2026-06-10 03:00:00', new \DateTimeZone('Africa/Dakar'));

        $result = $service->toBusinessDate($instant);

        self::assertSame('2026-06-09', $result->format('Y-m-d'));
        self::assertSame('00:00:00', $result->format('H:i:s'));
        self::assertSame('Africa/Dakar', $result->getTimezone()->getName());
    }

    public function testToBusinessDateAfterCutoffReturnsToday(): void
    {
        $service = $this->makeService(5);

        // 07:00 le 10/06 → business date = 10/06
        $instant = new \DateTimeImmutable('2026-06-10 07:00:00', new \DateTimeZone('Africa/Dakar'));

        $result = $service->toBusinessDate($instant);

        self::assertSame('2026-06-10', $result->format('Y-m-d'));
    }

    public function testCutoffAtZeroBehavesAsCivilDate(): void
    {
        $service = $this->makeService(0);

        // À minuit pile, on est strictement >= 0 → business = aujourd'hui
        $instant = new \DateTimeImmutable('2026-06-10 00:00:00', new \DateTimeZone('Africa/Dakar'));
        self::assertSame('2026-06-10', $service->toBusinessDate($instant)->format('Y-m-d'));

        // 23:59 → toujours aujourd'hui
        $instant = new \DateTimeImmutable('2026-06-10 23:59:00', new \DateTimeZone('Africa/Dakar'));
        self::assertSame('2026-06-10', $service->toBusinessDate($instant)->format('Y-m-d'));
    }

    public function testCutoffAtTwentyThreeBehavesAsAlmostCivilDateMinusOne(): void
    {
        $service = $this->makeService(23);

        // 22:59 → strictement < 23 → business = veille
        $instant = new \DateTimeImmutable('2026-06-10 22:59:00', new \DateTimeZone('Africa/Dakar'));
        self::assertSame('2026-06-09', $service->toBusinessDate($instant)->format('Y-m-d'));

        // 23:00 → business = aujourd'hui
        $instant = new \DateTimeImmutable('2026-06-10 23:00:00', new \DateTimeZone('Africa/Dakar'));
        self::assertSame('2026-06-10', $service->toBusinessDate($instant)->format('Y-m-d'));
    }

    public function testToBusinessDateUsesTenantTimezone(): void
    {
        // Tenant en TZ Asia/Tokyo (UTC+9). Un instant UTC 22:00 = 07:00 Tokyo
        // le lendemain → business date Tokyo = jour Tokyo.
        $service = $this->makeService(5, 'Asia/Tokyo');

        $instant = new \DateTimeImmutable('2026-06-09 22:00:00', new \DateTimeZone('UTC'));
        $result  = $service->toBusinessDate($instant);

        // 22:00 UTC = 07:00 Tokyo le 10/06 → après cutoff 5h Tokyo → 10/06
        self::assertSame('2026-06-10', $result->format('Y-m-d'));
        self::assertSame('Asia/Tokyo', $result->getTimezone()->getName());
    }

    public function testGetCurrentBusinessDateIsAtMidnightInTenantTimezone(): void
    {
        $service = $this->makeService(5, 'Africa/Dakar');

        $result = $service->getCurrentBusinessDate();

        self::assertSame('00:00:00', $result->format('H:i:s'));
        self::assertSame('Africa/Dakar', $result->getTimezone()->getName());
    }
}
