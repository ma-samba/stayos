<?php

namespace App\DataFixtures;

use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class ReservationFixtures extends Fixture implements DependentFixtureInterface
{
    public const RES_ACTIVE_1 = 'reservation-active-1';
    public const RES_ACTIVE_2 = 'reservation-active-2';
    public const RES_FUTURE_1 = 'reservation-future-1';
    public const RES_PAST_1   = 'reservation-past-1';

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDependencies(): array
    {
        return [GuestFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Tenant $savana */
        $savana = $this->getReference(TenantFixtures::SAVANA, Tenant::class);
        $schemaName = $savana->getSchemaName();

        $tz = new \DateTimeZone('Africa/Dakar');

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            // ── Réservation en cours (check-in aujourd'hui) ──
            /** @var Room $room101 */
            $room101 = $this->getReference(HotelDataFixtures::ROOM_SAVANA_101, Room::class);
            /** @var Guest $amadou */
            $amadou = $this->getReference(GuestFixtures::GUEST_AMADOU_DIALLO, Guest::class);

            $res1 = new Reservation();
            $res1->setConfirmationNumber('RES-2026-04821');
            $res1->setGuest($amadou);
            $res1->setRoom($room101);
            $res1->setStatusEnum(ReservationStatus::CHECKED_IN);
            $res1->setCheckIn(new \DateTimeImmutable('today', $tz));
            $res1->setCheckOut(new \DateTimeImmutable('+3 days', $tz));
            $res1->setAdults(2);
            $res1->setRateXof('35000.00');
            $res1->setTotalXof('105000.00');
            $res1->setSource('direct');
            $res1->setCheckedInAt(new \DateTimeImmutable('today 14:00', $tz));

            $manager->persist($res1);
            $this->addReference(self::RES_ACTIVE_1, $res1);

            // ── Réservation confirmée (arrivée demain) ──
            /** @var Room $room201 */
            $room201 = $this->getReference(HotelDataFixtures::ROOM_SAVANA_201, Room::class);
            /** @var Guest $pierre */
            $pierre = $this->getReference(GuestFixtures::GUEST_PIERRE_MARTIN, Guest::class);

            $res2 = new Reservation();
            $res2->setConfirmationNumber('RES-2026-04822');
            $res2->setGuest($pierre);
            $res2->setRoom($room201);
            $res2->setStatusEnum(ReservationStatus::CONFIRMED);
            $res2->setCheckIn(new \DateTimeImmutable('+1 day', $tz));
            $res2->setCheckOut(new \DateTimeImmutable('+4 days', $tz));
            $res2->setAdults(1);
            $res2->setRateXof('55000.00');
            $res2->setTotalXof('165000.00');
            $res2->setSource('booking_com');
            $res2->setDepositXof('55000.00');

            $manager->persist($res2);
            $this->addReference(self::RES_FUTURE_1, $res2);

            // ── Réservation en cours — Suite ──
            /** @var Room $room301 */
            $room301 = $this->getReference(HotelDataFixtures::ROOM_SAVANA_301, Room::class);
            /** @var Guest $fatou */
            $fatou = $this->getReference(GuestFixtures::GUEST_FATOU_NDIAYE, Guest::class);

            $res3 = new Reservation();
            $res3->setConfirmationNumber('RES-2026-04823');
            $res3->setGuest($fatou);
            $res3->setRoom($room301);
            $res3->setStatusEnum(ReservationStatus::CHECKED_IN);
            $res3->setCheckIn(new \DateTimeImmutable('-2 days', $tz));
            $res3->setCheckOut(new \DateTimeImmutable('+1 day', $tz));
            $res3->setAdults(2);
            $res3->setChildren(1);
            $res3->setRateXof('95000.00');
            $res3->setTotalXof('285000.00');
            $res3->setSource('direct');
            $res3->setCheckedInAt(new \DateTimeImmutable('-2 days 14:30', $tz));

            $manager->persist($res3);
            $this->addReference(self::RES_ACTIVE_2, $res3);

            // ── Réservation passée (terminée) ──
            $res4 = new Reservation();
            $res4->setConfirmationNumber('RES-2026-04800');
            $res4->setGuest($amadou);
            $res4->setRoom($room201);
            $res4->setStatusEnum(ReservationStatus::CHECKED_OUT);
            $res4->setCheckIn(new \DateTimeImmutable('-10 days', $tz));
            $res4->setCheckOut(new \DateTimeImmutable('-7 days', $tz));
            $res4->setAdults(2);
            $res4->setRateXof('55000.00');
            $res4->setTotalXof('165000.00');
            $res4->setSource('direct');
            $res4->setCheckedInAt(new \DateTimeImmutable('-10 days 15:00', $tz));
            $res4->setCheckedOutAt(new \DateTimeImmutable('-7 days 11:00', $tz));

            $manager->persist($res4);
            $this->addReference(self::RES_PAST_1, $res4);

            // ── Dériver le statut OCCUPIED des réservations CHECKED_IN ──
            // Marquer les chambres qui ont un client présent aujourd'hui.
            $today = new \DateTimeImmutable('today', $tz);
            $checkedInReservations = [$res1, $res3]; // les deux CHECKED_IN

            foreach ($checkedInReservations as $res) {
                if (
                    $res->getStatus() === ReservationStatus::CHECKED_IN->value
                    && $res->getCheckIn() <= $today
                    && $res->getCheckOut() > $today
                ) {
                    $res->getRoom()->setStatusEnum(RoomStatus::OCCUPIED);
                }
            }

            $manager->flush();

        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }
}
