<?php

namespace App\DataFixtures;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Room\Domain\Entity\Room;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class HousekeepingFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDependencies(): array
    {
        return [HotelDataFixtures::class];
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

            /** @var StaffUser $housekeeper */
            $housekeeper = $this->getReference(HotelDataFixtures::STAFF_SAVANA_HOUSEKEEPER, StaffUser::class);

            // Chambre 104 — départ aujourd'hui, tâche assignée (en cours)
            $room104 = $this->getReference('room-savana-104', Room::class);

            $task1 = new CleaningTask();
            $task1->setRoom($room104);
            $task1->setAssignedTo($housekeeper);
            $task1->setStatusEnum(CleaningStatus::IN_PROGRESS);
            $task1->setTypeEnum(CleaningType::DEPARTURE);
            $task1->setScheduledAt(new \DateTimeImmutable('today 10:00', $tz));
            $task1->setStartedAt(new \DateTimeImmutable('today 10:15', $tz));
            $manager->persist($task1);

            // Chambre 102 — stay_over, en attente
            $room102 = $this->getReference('room-savana-102', Room::class);

            $task2 = new CleaningTask();
            $task2->setRoom($room102);
            $task2->setTypeEnum(CleaningType::STAY_OVER);
            $task2->setStatusEnum(CleaningStatus::PENDING);
            $task2->setScheduledAt(new \DateTimeImmutable('today 09:00', $tz));
            $manager->persist($task2);

            // Chambre 204 — maintenance
            $room204 = $this->getReference('room-savana-204', Room::class);

            $task3 = new CleaningTask();
            $task3->setRoom($room204);
            $task3->setAssignedTo($housekeeper);
            $task3->setTypeEnum(CleaningType::MAINTENANCE);
            $task3->setStatusEnum(CleaningStatus::PENDING);
            $task3->setScheduledAt(new \DateTimeImmutable('today 08:00', $tz));
            $task3->setNotes('Robinet cassé à réparer');
            $manager->persist($task3);

            // Chambre 103 — terminée ce matin
            $room103 = $this->getReference('room-savana-103', Room::class);

            $task4 = new CleaningTask();
            $task4->setRoom($room103);
            $task4->setAssignedTo($housekeeper);
            $task4->setTypeEnum(CleaningType::STAY_OVER);
            $task4->setStatusEnum(CleaningStatus::DONE);
            $task4->setScheduledAt(new \DateTimeImmutable('today 08:30', $tz));
            $task4->setStartedAt(new \DateTimeImmutable('today 08:35', $tz));
            $task4->setCompletedAt(new \DateTimeImmutable('today 09:10', $tz));
            $manager->persist($task4);

            $manager->flush();

        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }
}
