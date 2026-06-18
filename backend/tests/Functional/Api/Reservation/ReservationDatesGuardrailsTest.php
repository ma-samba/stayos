<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Reservation;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Hotfix entre 14-B.1.2.2 et 14-B.2 — Garde-fou dates create/update
 *
 * Tests fonctionnels du garde-fou serveur sur la création et la
 * modification de réservations.
 *
 * Règles couvertes :
 * 1. checkOut < today → refus (séjour entièrement passé)
 * 2. checkIn < today - 30j → refus à la création (saisie erronée)
 * 3. Update sans toucher au checkIn sur une résa ancienne → accepté
 *    (la règle 2 n'est appliquée que si le checkIn est dans le DTO)
 */
class ReservationDatesGuardrailsTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;
    private string $tz = 'Africa/Dakar';

    private const HOST = 'savana.localhost';
    private const CONFIRMATION_PREFIX = 'TEST-DATES-GUARD-';

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
                "DELETE FROM audit_logs
                 WHERE action IN ('reservation.created', 'reservation.updated')
                   AND created_at > NOW() - INTERVAL '1 hour'"
            );
            $this->conn->executeStatement('DELETE FROM daily_closes');
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function date(string $expr): string
    {
        return (new \DateTimeImmutable($expr, new \DateTimeZone($this->tz)))->format('Y-m-d');
    }

    /**
     * Construit un payload de création de réservation. Pioche le premier
     * roomId et guestId disponibles via l'API (mêmes hypothèses que
     * ReservationPromoTest).
     */
    private function buildCreateDto(string $checkIn, string $checkOut): array
    {
        $rooms = $this->apiRequest('GET', '/api/rooms', self::HOST);
        $roomId = $rooms['data'][0]['id'] ?? null;
        self::assertNotNull($roomId, 'Au moins une chambre doit exister en fixtures');

        $guests = $this->apiRequest('GET', '/api/guests', self::HOST);
        $guestId = $guests['data'][0]['id'] ?? null;
        self::assertNotNull($guestId, 'Au moins un client doit exister en fixtures');

        return [
            'roomId'   => $roomId,
            'guestId'  => $guestId,
            'checkIn'  => $checkIn,
            'checkOut' => $checkOut,
            'adults'   => 1,
        ];
    }

    /**
     * Insère une résa PENDING dont le checkIn est très ancien. Sert à
     * tester la modification d'une résa hors fenêtre 30j (le garde-fou
     * checkIn >= today-30j ne doit pas se déclencher si le DTO ne touche
     * pas au checkIn). Retourne son UUID.
     */
    private function seedAncientPendingReservation(
        int $daysAgoCheckIn,
        int $daysAgoCheckOut,
    ): string {
        $tz       = new \DateTimeZone($this->tz);
        $checkIn  = (new \DateTimeImmutable("-{$daysAgoCheckIn} days", $tz))->format('Y-m-d');
        $checkOut = $daysAgoCheckOut === 0
            ? (new \DateTimeImmutable('today', $tz))->format('Y-m-d')
            : (new \DateTimeImmutable("-{$daysAgoCheckOut} days", $tz))->format('Y-m-d');

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
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
                 VALUES (:id, :conf, :guest, :room, 'pending', :checkIn, :checkOut,
                         1, 0, '35000.00', '35000.00', 'direct', NOW(), NOW())",
                [
                    'id'       => $id,
                    'conf'     => self::CONFIRMATION_PREFIX . 'UPD',
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

    // ── Tests création ──

    public function testCreateReservationWithFullyPastStayRefused(): void
    {
        $this->loginAsManager();

        $body = $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            $this->buildCreateDto(
                checkIn:  $this->date('-10 days'),
                checkOut: $this->date('-7 days'),
            ),
        );

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString(
            'terminé',
            strtolower($body['error'] ?? ''),
            'Message doit mentionner que le séjour est terminé',
        );
    }

    public function testCreateReservationWithVeryOldCheckInRefused(): void
    {
        $this->loginAsManager();

        // checkIn = -45j : au-delà du cap 30j. checkOut = +1j pour ne pas
        // déclencher la 1ère règle (séjour passé).
        $body = $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            $this->buildCreateDto(
                checkIn:  $this->date('-45 days'),
                checkOut: $this->date('+1 day'),
            ),
        );

        $this->assertApiError('BUSINESS_RULE', 422);
        self::assertStringContainsString(
            'ancienne',
            strtolower($body['error'] ?? ''),
            'Message doit mentionner que la date d\'arrivée est trop ancienne',
        );
    }

    public function testCreateWalkInRetroactiveAccepted(): void
    {
        $this->loginAsManager();

        // Walk-in régularisation : 3 nuits qui se terminent today.
        // Cas métier légitime.
        $body = $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            $this->buildCreateDto(
                checkIn:  $this->date('-3 days'),
                checkOut: $this->date('today'),
            ),
        );

        $this->assertApiSuccess($body, 201);
        self::assertSame('confirmed', $body['data']['status'] ?? null);
    }

    public function testCreateFutureReservationAccepted(): void
    {
        $this->loginAsManager();

        $body = $this->apiRequest(
            'POST', '/api/reservations', self::HOST,
            $this->buildCreateDto(
                checkIn:  $this->date('+5 days'),
                checkOut: $this->date('+10 days'),
            ),
        );

        $this->assertApiSuccess($body, 201);
    }

    // ── Test update ──

    public function testUpdateAncientReservationWithoutTouchingCheckInAccepted(): void
    {
        // Insertion BDD directe : impossible de créer une telle résa via
        // l'API (rejetée par le garde-fou). On vérifie ensuite qu'une
        // modification limitée aux notes passe — le garde-fou checkIn
        // >= today-30j ne doit pas se déclencher si le DTO ne touche
        // pas au checkIn.
        $this->loginAsManager();

        $reservationId = $this->seedAncientPendingReservation(
            daysAgoCheckIn:  60,
            daysAgoCheckOut: 0, // checkOut = today (1ère règle ne se déclenche pas)
        );

        $body = $this->apiRequest(
            'PUT', "/api/reservations/$reservationId", self::HOST,
            ['notes' => 'Note ajoutée tardivement'],
        );

        $this->assertApiSuccess($body, 200);
    }
}
