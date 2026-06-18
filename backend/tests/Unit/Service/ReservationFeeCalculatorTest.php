<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\CancellationPolicy;
use App\Hotel\Reservation\Domain\Enum\NoShowPolicy;
use App\Hotel\Reservation\Domain\Service\ReservationFeeCalculator;
use PHPUnit\Framework\TestCase;

class ReservationFeeCalculatorTest extends TestCase
{
    private ReservationFeeCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ReservationFeeCalculator();
    }

    // ── No-show ────────────────────────────────────────────────

    public function testNoShowNoneAlwaysReturnsZero(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+1 day');

        self::assertSame('0.00', $this->calc->computeNoShowFee($res, NoShowPolicy::NONE));
    }

    public function testNoShowFirstNightReturnsRateXof(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+1 day');

        self::assertSame('45000.00', $this->calc->computeNoShowFee($res, NoShowPolicy::FIRST_NIGHT));
    }

    public function testNoShowFullReturnsTotalXof(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+1 day');

        self::assertSame('135000.00', $this->calc->computeNoShowFee($res, NoShowPolicy::FULL));
    }

    // ── Cancellation FLEXIBLE ─────────────────────────────────

    public function testCancellationFlexibleAlwaysFree(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+72 hours');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::FLEXIBLE, $now);

        self::assertSame('0.00', $quote['amountXof']);
        self::assertStringContainsString('flexible', strtolower($quote['reason']));
        self::assertGreaterThan(0, $quote['hoursBefore']);
    }

    public function testCancellationFlexibleEvenLastMinute(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+2 hours');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::FLEXIBLE, $now);

        self::assertSame('0.00', $quote['amountXof']);
    }

    // ── Cancellation STRICT ───────────────────────────────────

    public function testCancellationStrictAlwaysFirstNight(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+10 days');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::STRICT, $now);

        self::assertSame('45000.00', $quote['amountXof']);
        self::assertStringContainsString('1ère nuit', $quote['reason']);
    }

    // ── Cancellation MODERATE ─────────────────────────────────

    public function testCancellationModerateMoreThan48hIsFree(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+72 hours');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::MODERATE, $now);

        self::assertSame('0.00', $quote['amountXof']);
        self::assertStringContainsString('> 48', $quote['reason']);
    }

    public function testCancellationModerateBetween24And48hChargesFirstNight(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+30 hours');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::MODERATE, $now);

        self::assertSame('45000.00', $quote['amountXof']);
        self::assertStringContainsString('24-48', $quote['reason']);
    }

    public function testCancellationModerateLessThan24hChargesTotal(): void
    {
        $res = $this->makeReservation('45000.00', '135000.00', '+12 hours');
        $now = new \DateTimeImmutable('now');

        $quote = $this->calc->computeCancellationFee($res, CancellationPolicy::MODERATE, $now);

        self::assertSame('135000.00', $quote['amountXof']);
        self::assertStringContainsString('< 24', $quote['reason']);
    }

    // ── hoursBetween edge cases ───────────────────────────────

    public function testHoursBetweenReturnsZeroIfTargetInThePast(): void
    {
        $now    = new \DateTimeImmutable('2026-06-09 10:00:00');
        $passed = new \DateTimeImmutable('2026-06-09 09:00:00');

        self::assertSame(0, $this->calc->hoursBetween($now, $passed));
    }

    public function testHoursBetweenComputesFullHours(): void
    {
        $now    = new \DateTimeImmutable('2026-06-09 10:00:00');
        $later  = new \DateTimeImmutable('2026-06-10 12:30:00'); // +26h30

        self::assertSame(26, $this->calc->hoursBetween($now, $later));
    }

    // ── Helper ─────────────────────────────────────────────────

    /**
     * Construit une Reservation minimale via reflection (sans Doctrine,
     * juste les champs lus par le calculator).
     */
    private function makeReservation(string $rateXof, string $totalXof, string $checkInOffset): Reservation
    {
        $res = new Reservation();

        $ref = new \ReflectionClass($res);
        foreach ([
            'rateXof'  => $rateXof,
            'totalXof' => $totalXof,
            'checkIn'  => new \DateTimeImmutable($checkInOffset),
            'checkOut' => new \DateTimeImmutable($checkInOffset . ' + 3 days'),
        ] as $field => $value) {
            $prop = $ref->getProperty($field);
            $prop->setValue($res, $value);
        }

        return $res;
    }
}
