<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Reservation;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hotfix entre 14-B.1.2.2 et 14-B.2 — Garde-fou check-in
 *
 * Tests fonctionnels du garde-fou serveur : refuser le check-in si
 * le séjour prévu est déjà terminé (today > checkOut). Pattern
 * symétrique du garde-fou markNoShow (Sprint 14-A.2 Dette 3) qui
 * refuse un no-show futur.
 *
 * Insertion BDD directe pour pouvoir simuler une résa expirée
 * (impossible via l'API standard qui rejette les dates passées).
 */
class CheckInGuardrailsTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST    = 'savana.localhost';
    private const MANAGER = 'admin@savana-hotel.sn';
    private const MGR_PWD = 'admin123';

    private const CONFIRMATION_PREFIX = 'TEST-CHECKIN-GUARD-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        self::assertNotNull($tenant);
        $this->schema = $tenant->getSchemaName();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                'DELETE FROM reservations WHERE confirmation_number LIKE :p',
                ['p' => self::CONFIRMATION_PREFIX . '%']
            );
            $this->conn->executeStatement(
                "DELETE FROM audit_logs WHERE action = 'reservation.checkin'"
            );
            $this->conn->executeStatement('DELETE FROM daily_closes');
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère une résa CONFIRMED dont le séjour prévu est dans le passé
     * (résa qui aurait dû être marquée no-show). Retourne son UUID.
     */
    private function seedExpiredConfirmedReservation(
        int $daysAgoCheckIn,
        int $daysAgoCheckOut,
        string $marker = 'EXP',
    ): string {
        $tz       = new \DateTimeZone('Africa/Dakar');
        $checkIn  = (new \DateTimeImmutable("-{$daysAgoCheckIn} days", $tz))->format('Y-m-d');
        $checkOut = (new \DateTimeImmutable("-{$daysAgoCheckOut} days", $tz))->format('Y-m-d');

        return $this->insertReservation($checkIn, $checkOut, $marker);
    }

    /**
     * Insère une résa CONFIRMED day-use : check-in et check-out le même
     * jour (today). Retourne son UUID. Sert à prouver que le garde-fou
     * `<` strict accepte ce cas légitime.
     */
    private function seedConfirmedReservationToday(string $marker = 'TODAY'): string
    {
        $tz       = new \DateTimeZone('Africa/Dakar');
        $today    = (new \DateTimeImmutable('today', $tz))->format('Y-m-d');

        return $this->insertReservation($today, $today, $marker);
    }

    private function insertReservation(
        string $checkIn,
        string $checkOut,
        string $marker,
    ): string {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            // Première chambre active disponible sur la période. Pour
            // le day-use (checkIn==checkOut), on relâche la contrainte
            // (le SELECT NOT EXISTS ne matche jamais si checkIn==checkOut).
            $roomId = $this->conn->executeQuery(
                "SELECT r.id FROM rooms r
                 WHERE r.is_active = TRUE
                 AND NOT EXISTS (
                     SELECT 1 FROM reservations res
                     WHERE res.room_id = r.id
                       AND res.status IN ('confirmed','checked_in')
                       AND res.check_in <= :checkOut AND res.check_out >= :checkIn
                 )
                 ORDER BY r.number ASC LIMIT 1",
                ['checkIn' => $checkIn, 'checkOut' => $checkOut]
            )->fetchOne();
            self::assertNotFalse($roomId, 'Chambre libre attendue.');

            $guestId = $this->conn->executeQuery('SELECT id FROM guests LIMIT 1')->fetchOne();
            $id      = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();

            $this->conn->executeStatement(
                "INSERT INTO reservations
                 (id, confirmation_number, guest_id, room_id, status,
                  check_in, check_out, adults, children, rate_xof, total_xof,
                  source, created_at, updated_at)
                 VALUES (:id, :conf, :guest, :room, 'confirmed', :checkIn, :checkOut,
                         1, 0, '35000.00', '35000.00', 'direct', NOW(), NOW())",
                [
                    'id'       => $id,
                    'conf'     => self::CONFIRMATION_PREFIX . $marker,
                    'guest'    => $guestId,
                    'room'     => $roomId,
                    'checkIn'  => $checkIn,
                    'checkOut' => $checkOut,
                ]
            );
            return (string) $id;
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testCheckInOnExpiredReservationRefused(): void
    {
        // Résa expirée : séjour 9 → 6 jours avant today, restée
        // CONFIRMED par négligence. Aurait dû être no-show.
        $id    = $this->seedExpiredConfirmedReservation(
            daysAgoCheckIn:  9,
            daysAgoCheckOut: 6,
            marker:          'E1',
        );
        $token = $this->login(self::MANAGER, self::MGR_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/checkin", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString(
            'expiré',
            strtolower($resp['error'] ?? ''),
            'Message doit mentionner que le séjour est expiré',
        );
    }

    public function testCheckInOnTodayCheckOutAccepted(): void
    {
        // Day-use : checkIn == checkOut == today. Le garde-fou en
        // comparaison `<` strict ne doit PAS bloquer ce cas
        // (today < today est faux).
        $id    = $this->seedConfirmedReservationToday('T1');
        $token = $this->login(self::MANAGER, self::MGR_PWD, self::HOST);

        $resp = $this->apiRequest(
            'POST', "/api/reservations/$id/checkin", self::HOST,
            headers: ['Authorization' => "Bearer $token"]
        );

        $this->assertApiSuccess($resp);
        self::assertSame('checked_in', $resp['data']['status'] ?? null);
    }
}
