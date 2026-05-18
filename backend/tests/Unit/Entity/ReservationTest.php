<?php

namespace App\Tests\Unit\Entity;

use App\Hotel\Reservation\Domain\Entity\Reservation;
use PHPUnit\Framework\TestCase;

class ReservationTest extends TestCase
{
    private function makeReservation(string $checkIn, string $checkOut, string $rate): Reservation
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $res = new Reservation();
        $res->setCheckIn(new \DateTimeImmutable($checkIn, $tz));
        $res->setCheckOut(new \DateTimeImmutable($checkOut, $tz));
        $res->setRateXof($rate);
        $res->setTotalXof('0.00'); // placeholder — calculated separately
        $res->setConfirmationNumber('RES-TEST-001');
        return $res;
    }

    public function testGetNightsSimple(): void
    {
        $res = $this->makeReservation('2026-05-12', '2026-05-15', '45000.00');
        $this->assertSame(3, $res->getNights());
    }

    public function testGetNightsOneNight(): void
    {
        $res = $this->makeReservation('2026-06-01', '2026-06-02', '35000.00');
        $this->assertSame(1, $res->getNights());
    }

    public function testGetNightsCrossMonth(): void
    {
        $res = $this->makeReservation('2026-05-30', '2026-06-02', '55000.00');
        $this->assertSame(3, $res->getNights());
    }

    public function testGetCalculatedTotalXof(): void
    {
        $res = $this->makeReservation('2026-05-12', '2026-05-15', '45000.00');
        // 3 nuits × 45 000 = 135 000
        $this->assertSame('135000.00', $res->getCalculatedTotalXof());
    }

    public function testGetCalculatedTotalXofOneNight(): void
    {
        $res = $this->makeReservation('2026-06-01', '2026-06-02', '35000.00');
        $this->assertSame('35000.00', $res->getCalculatedTotalXof());
    }

    public function testGetCalculatedTotalXofSuiteWeekend(): void
    {
        $res = $this->makeReservation('2026-06-05', '2026-06-08', '95000.00');
        // 3 nuits × 95 000 = 285 000
        $this->assertSame('285000.00', $res->getCalculatedTotalXof());
    }

    public function testDefaultAdultsIsOne(): void
    {
        $res = new Reservation();
        $this->assertSame(1, $res->getAdults());
    }

    public function testDefaultChildrenIsZero(): void
    {
        $res = new Reservation();
        $this->assertSame(0, $res->getChildren());
    }

    public function testDefaultStatusIsConfirmed(): void
    {
        $res = new Reservation();
        $this->assertSame('confirmed', $res->getStatus());
    }

    public function testCreatedAtIsSetOnConstruct(): void
    {
        $before = new \DateTimeImmutable();
        $res = new Reservation();
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $res->getCreatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $res->getCreatedAt()->getTimestamp());
    }
}
