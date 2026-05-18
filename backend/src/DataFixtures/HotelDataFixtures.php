<?php

namespace App\DataFixtures;

use App\Hotel\Property\Domain\Entity\Floor;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class HotelDataFixtures extends Fixture implements DependentFixtureInterface
{
    // Références hotel profiles
    public const HOTEL_SAVANA  = 'hotel-savana';
    public const HOTEL_VILLA   = 'hotel-villa';

    // Références rooms Savana (pour les fixtures suivantes)
    public const ROOM_SAVANA_101 = 'room-savana-101';
    public const ROOM_SAVANA_201 = 'room-savana-201';
    public const ROOM_SAVANA_301 = 'room-savana-301';

    // Références rooms Villa
    public const ROOM_VILLA_101 = 'room-villa-101';

    // Références staff Savana
    public const STAFF_SAVANA_ADMIN       = 'staff-savana-admin';
    public const STAFF_SAVANA_RECEPTION   = 'staff-savana-reception';
    public const STAFF_SAVANA_HOUSEKEEPER = 'staff-savana-housekeeper';

    // Références staff Villa
    public const STAFF_VILLA_ADMIN = 'staff-villa-admin';

    public function __construct(
        private readonly Connection                  $connection,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function getDependencies(): array
    {
        return [TenantFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Tenant $savana */
        $savana = $this->getReference(TenantFixtures::SAVANA, Tenant::class);
        /** @var Tenant $villa */
        $villa  = $this->getReference(TenantFixtures::VILLA_COLLINES, Tenant::class);

        $this->loadHotelData($manager, $savana, 'savana');
        $this->loadHotelData($manager, $villa, 'villa');
    }

    private function loadHotelData(ObjectManager $manager, Tenant $tenant, string $hotelKey): void
    {
        $schemaName = $tenant->getSchemaName();

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            if ($hotelKey === 'savana') {
                $this->loadSavanaData($manager);
            } else {
                $this->loadVillaData($manager);
            }

            $manager->flush();

        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }

    private function loadSavanaData(ObjectManager $manager): void
    {
        // ── Hotel Profile ──
        $hotel = new HotelProfile();
        $hotel->setName('Hôtel Savana Dakar');
        $hotel->setAddress('Avenue Cheikh Anta Diop, Dakar');
        $hotel->setCity('Dakar');
        $hotel->setCountry('SN');
        $hotel->setPhone('+221 33 867 00 00');
        $hotel->setEmail('contact@savana-hotel.sn');
        $hotel->setStarRating(4);
        $hotel->setTotalRooms(20);
        $hotel->setCheckInTime('14:00');
        $hotel->setCheckOutTime('11:00');
        $manager->persist($hotel);
        $this->addReference(self::HOTEL_SAVANA, $hotel);

        // ── Floors ──
        foreach ([1, 2, 3] as $num) {
            $floor = new Floor();
            $floor->setNumber($num);
            $floor->setName("Étage {$num}");
            $manager->persist($floor);
            $this->addReference("floor-savana-{$num}", $floor);
        }

        // ── Room Types ──
        $typeStandard = new RoomType();
        $typeStandard->setName('Standard');
        $typeStandard->setBaseRateXof('35000.00');
        $typeStandard->setMaxOccupancy(2);
        $typeStandard->setBedConfiguration(['beds' => [['type' => 'double', 'count' => 1]]]);
        $typeStandard->setAmenities(['wifi', 'ac', 'tv']);
        $typeStandard->setSortOrder(1);
        $manager->persist($typeStandard);

        $typeDeluxe = new RoomType();
        $typeDeluxe->setName('Deluxe');
        $typeDeluxe->setBaseRateXof('55000.00');
        $typeDeluxe->setMaxOccupancy(3);
        $typeDeluxe->setBedConfiguration(['beds' => [['type' => 'king', 'count' => 1]]]);
        $typeDeluxe->setAmenities(['wifi', 'ac', 'tv', 'minibar', 'safe']);
        $typeDeluxe->setSortOrder(2);
        $manager->persist($typeDeluxe);

        $typeSuite = new RoomType();
        $typeSuite->setName('Suite');
        $typeSuite->setBaseRateXof('95000.00');
        $typeSuite->setMaxOccupancy(4);
        $typeSuite->setBedConfiguration(['beds' => [['type' => 'king', 'count' => 1], ['type' => 'sofa', 'count' => 1]]]);
        $typeSuite->setAmenities(['wifi', 'ac', 'tv', 'minibar', 'safe', 'sea_view', 'jacuzzi']);
        $typeSuite->setSortOrder(3);
        $manager->persist($typeSuite);

        // ── Rooms ──
        $roomData = [
            // floor 1 — Standard
            ['number' => '101', 'type' => $typeStandard, 'floor' => 1, 'status' => RoomStatus::OCCUPIED],
            ['number' => '102', 'type' => $typeStandard, 'floor' => 1, 'status' => RoomStatus::AVAILABLE],
            ['number' => '103', 'type' => $typeStandard, 'floor' => 1, 'status' => RoomStatus::OCCUPIED],
            ['number' => '104', 'type' => $typeStandard, 'floor' => 1, 'status' => RoomStatus::CLEANING],
            // floor 2 — Deluxe
            ['number' => '201', 'type' => $typeDeluxe, 'floor' => 2, 'status' => RoomStatus::OCCUPIED],
            ['number' => '202', 'type' => $typeDeluxe, 'floor' => 2, 'status' => RoomStatus::AVAILABLE],
            ['number' => '203', 'type' => $typeDeluxe, 'floor' => 2, 'status' => RoomStatus::OCCUPIED],
            ['number' => '204', 'type' => $typeDeluxe, 'floor' => 2, 'status' => RoomStatus::MAINTENANCE],
            // floor 3 — Suite
            ['number' => '301', 'type' => $typeSuite, 'floor' => 3, 'status' => RoomStatus::OCCUPIED],
            ['number' => '302', 'type' => $typeSuite, 'floor' => 3, 'status' => RoomStatus::AVAILABLE],
        ];

        foreach ($roomData as $data) {
            /** @var Floor $floor */
            $floor = $this->getReference("floor-savana-{$data['floor']}", Floor::class);

            $room = new Room();
            $room->setFloor($floor);
            $room->setType($data['type']);
            $room->setNumber($data['number']);
            $room->setStatusEnum($data['status']);
            $manager->persist($room);
            $this->addReference("room-savana-{$data['number']}", $room);
        }

        // ── Staff ──
        $this->createStaff($manager, 'admin@savana-hotel.sn', 'admin123', 'Ibrahima', 'Diallo', 'MANAGER', self::STAFF_SAVANA_ADMIN);
        $this->createStaff($manager, 'reception@savana-hotel.sn', 'recep123', 'Aminata', 'Ndiaye', 'RECEPTIONIST', self::STAFF_SAVANA_RECEPTION);
        $this->createStaff($manager, 'menage@savana-hotel.sn', 'menage123', 'Rokhaya', 'Fall', 'HOUSEKEEPER', self::STAFF_SAVANA_HOUSEKEEPER);
    }

    private function loadVillaData(ObjectManager $manager): void
    {
        // ── Hotel Profile ──
        $hotel = new HotelProfile();
        $hotel->setName('Villa Collines Saly');
        $hotel->setAddress('Route de Saly, Mbour');
        $hotel->setCity('Saly');
        $hotel->setCountry('SN');
        $hotel->setPhone('+221 33 957 00 00');
        $hotel->setEmail('contact@villa-collines.sn');
        $hotel->setStarRating(3);
        $hotel->setTotalRooms(8);
        $hotel->setCheckInTime('15:00');
        $hotel->setCheckOutTime('12:00');
        $manager->persist($hotel);
        $this->addReference(self::HOTEL_VILLA, $hotel);

        // ── Floor ──
        $floor1 = new Floor();
        $floor1->setNumber(1);
        $floor1->setName('Rez-de-chaussée');
        $manager->persist($floor1);
        $this->addReference('floor-villa-1', $floor1);

        // ── Room Type ──
        $typeStd = new RoomType();
        $typeStd->setName('Standard');
        $typeStd->setBaseRateXof('25000.00');
        $typeStd->setMaxOccupancy(2);
        $typeStd->setBedConfiguration(['beds' => [['type' => 'double', 'count' => 1]]]);
        $typeStd->setAmenities(['wifi', 'ac']);
        $typeStd->setSortOrder(1);
        $manager->persist($typeStd);

        // ── Rooms ──
        $roomNumbers = ['101', '102', '103', '104'];
        $statuses    = [RoomStatus::OCCUPIED, RoomStatus::AVAILABLE, RoomStatus::OCCUPIED, RoomStatus::AVAILABLE];

        foreach ($roomNumbers as $i => $number) {
            $room = new Room();
            $room->setFloor($floor1);
            $room->setType($typeStd);
            $room->setNumber("V{$number}");
            $room->setStatusEnum($statuses[$i]);
            $manager->persist($room);
            $this->addReference("room-villa-{$number}", $room);
        }

        // ── Staff ──
        $this->createStaff($manager, 'admin@villa-collines.sn', 'admin123', 'Moussa', 'Ba', 'MANAGER', self::STAFF_VILLA_ADMIN);
    }

    private function createStaff(
        ObjectManager $manager,
        string $email,
        string $plainPassword,
        string $firstName,
        string $lastName,
        string $role,
        string $refKey,
    ): void {
        $staff = new StaffUser();
        $staff->setEmail($email);
        $staff->setFirstName($firstName);
        $staff->setLastName($lastName);
        $staff->setRole($role);
        $staff->setPassword($this->passwordHasher->hashPassword($staff, $plainPassword));

        $manager->persist($staff);
        $this->addReference($refKey, $staff);
    }
}
