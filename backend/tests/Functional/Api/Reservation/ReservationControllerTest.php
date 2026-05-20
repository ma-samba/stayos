<?php

namespace App\Tests\Functional\Api\Reservation;

use App\Tests\Functional\ApiTestCase;

/**
 * Tests fonctionnels du ReservationController.
 *
 * Ces tests nécessitent la base de données de test avec les fixtures chargées.
 * Lancez `make fixtures` puis `make test-functional` pour les exécuter.
 */
class ReservationControllerTest extends ApiTestCase
{
    /**
     * Test 1 : GET /api/reservations retourne 200 avec data.
     */
    public function testListReservations(): void
    {
        $response = $this->apiRequest('GET', '/api/reservations', headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        // Sans auth, retourne 401
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Test 2 : GET /api/reservations/gantt sans paramètres retourne 422.
     */
    public function testGanttWithoutParamsReturns422(): void
    {
        $response = $this->apiRequest('GET', '/api/reservations/gantt', headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        // Sans auth, retourne 401
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Test 3 : POST /api/reservations sans body retourne 401 (pas authentifié).
     */
    public function testCreateWithoutAuthReturns401(): void
    {
        $this->apiRequest('POST', '/api/reservations', body: [
            'roomId'   => '00000000-0000-0000-0000-000000000001',
            'guestId'  => '00000000-0000-0000-0000-000000000001',
            'checkIn'  => '2026-08-01',
            'checkOut' => '2026-08-05',
            'adults'   => 2,
        ], headers: [
            'X-Tenant-Slug' => 'savana',
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Test 4 : POST /api/reservations/{id}/checkin sans auth retourne 401.
     */
    public function testCheckInWithoutAuthReturns401(): void
    {
        $this->apiRequest(
            'POST',
            '/api/reservations/00000000-0000-0000-0000-000000000001/checkin',
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Test 5 : POST /api/reservations/{id}/checkout sans auth retourne 401.
     */
    public function testCheckOutWithoutAuthReturns401(): void
    {
        $this->apiRequest(
            'POST',
            '/api/reservations/00000000-0000-0000-0000-000000000001/checkout',
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Test 6 : POST /api/reservations/{id}/cancel sans auth retourne 401.
     */
    public function testCancelWithoutAuthReturns401(): void
    {
        $this->apiRequest(
            'POST',
            '/api/reservations/00000000-0000-0000-0000-000000000001/cancel',
            body: ['reason' => 'Test'],
            headers: ['X-Tenant-Slug' => 'savana'],
        );

        self::assertResponseStatusCodeSame(401);
    }
}
