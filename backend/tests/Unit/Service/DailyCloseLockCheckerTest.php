<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\NightAudit\Domain\Entity\DailyClose;
use App\Hotel\NightAudit\Domain\Service\DailyCloseLockChecker;
use App\Hotel\NightAudit\Infrastructure\Repository\DailyCloseRepository;
use App\Shared\Exception\BusinessRuleException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DailyCloseLockCheckerTest extends TestCase
{
    private MockObject&DailyCloseRepository $repo;
    private DailyCloseLockChecker $checker;

    protected function setUp(): void
    {
        $this->repo    = $this->createMock(DailyCloseRepository::class);
        $this->checker = new DailyCloseLockChecker($this->repo);
    }

    public function testNoCloseEverAllowsAnyDate(): void
    {
        $this->repo->method('findLatestEffective')->willReturn(null);

        // Aucune exception
        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2026-06-09'));
        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2000-01-01'));

        self::assertNull($this->checker->getEffectiveLastClose());
    }

    public function testDateBeforeLastCloseIsRefused(): void
    {
        $this->repo->method('findLatestEffective')->willReturn(
            $this->makeClose('2026-06-09')
        );

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/clôturée/');

        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2026-06-08'));
    }

    public function testDateEqualToLastCloseIsRefused(): void
    {
        $this->repo->method('findLatestEffective')->willReturn(
            $this->makeClose('2026-06-09')
        );

        $this->expectException(BusinessRuleException::class);

        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2026-06-09'));
    }

    public function testDateAfterLastCloseIsAllowed(): void
    {
        $this->repo->method('findLatestEffective')->willReturn(
            $this->makeClose('2026-06-09')
        );

        // Pas d'exception
        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2026-06-10'));
        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2099-12-31'));

        self::assertSame('2026-06-09', $this->checker->getEffectiveLastClose()?->format('Y-m-d'));
    }

    public function testReopenedCloseIsBypass(): void
    {
        // findLatestEffective filtre déjà sur reopened_at IS NULL.
        // Donc si la dernière clôture est rouverte, le repo retournera null
        // (ou une plus ancienne effective). On simule null ici.
        $this->repo->method('findLatestEffective')->willReturn(null);

        // Une date dans le passé "anciennement close" mais maintenant rouverte
        // n'est plus verrouillée.
        $this->checker->assertCanModifyDate(new \DateTimeImmutable('2026-06-08'));

        self::assertNull($this->checker->getEffectiveLastClose());
    }

    private function makeClose(string $businessDate): DailyClose
    {
        $close = new DailyClose();
        $close->setBusinessDate(new \DateTimeImmutable($businessDate));
        return $close;
    }
}
