<?php

namespace App\Tests\Functional\Api\Reservation;

use App\Tests\Functional\ApiTestCase;

class ReservationPromoTest extends ApiTestCase
{
    /**
     */
    public function testCreateReservationViaApiAppliesPromoCode(): void
    {
        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Get a real room
        $rooms = $this->apiRequest(
            'GET', '/api/rooms', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );
        $roomId = $rooms['data'][0]['id'] ?? null;
        self::assertNotNull($roomId, 'Au moins une chambre doit exister en fixtures');
        $baseRate = (float) ($rooms['data'][0]['type']['baseRateXof'] ?? '0');

        // Get a real guest
        $guests = $this->apiRequest(
            'GET', '/api/guests', 'savana.localhost',
            headers: ['Authorization' => "Bearer $token"],
        );
        $guestId = $guests['data'][0]['id'] ?? null;
        self::assertNotNull($guestId, 'Au moins un client doit exister en fixtures');

        // Create reservation with promo code (3 nights to satisfy minNights=2)
        $response = $this->apiRequest(
            'POST', '/api/reservations', 'savana.localhost',
            [
                'roomId'    => $roomId,
                'guestId'   => $guestId,
                'checkIn'   => '2026-12-01',
                'checkOut'  => '2026-12-04',
                'adults'    => 1,
                'promoCode' => 'OUVERTURE2026',
            ],
            ['Authorization' => "Bearer $token"],
        );

        $this->assertApiSuccess($response, 201);

        $totalWithPromo = (float) $response['data']['totalXof'];
        $totalWithout   = $baseRate * 3;

        // The promo should reduce the total
        self::assertLessThan(
            $totalWithout,
            $totalWithPromo,
            "Le total avec promo ($totalWithPromo) devrait etre inferieur au total sans promo ($totalWithout)",
        );
    }
}
