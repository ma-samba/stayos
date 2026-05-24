<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Housekeeping\Domain\Service\HousekeepingService;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class HousekeepingServiceTest extends TestCase
{
    private HousekeepingService $service;
    private MockObject&EntityManagerInterface $em;
    private MockObject&AuditService $auditService;
    private MockObject&MercurePublisher $mercurePublisher;
    private MockObject&CleaningTaskRepository $taskRepo;
    private MockObject&ReservationRepository $reservationRepo;
    private MockObject&LoggerInterface $logger;
    private MockObject&StaffUser $staff;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->auditService     = $this->createMock(AuditService::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->taskRepo         = $this->createMock(CleaningTaskRepository::class);
        $this->reservationRepo  = $this->createMock(ReservationRepository::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new HousekeepingService(
            $this->em,
            $this->auditService,
            $this->mercurePublisher,
            $this->taskRepo,
            $this->reservationRepo,
            $this->logger,
        );

        $this->staff = $this->createMock(StaffUser::class);
    }

    private function makeTask(CleaningStatus $status, ?RoomStatus $roomStatus = null): CleaningTask
    {
        $room = $this->createMock(Room::class);
        $room->method('getId')->willReturn(Uuid::v4());
        $room->method('getNumber')->willReturn('101');
        $room->method('getStatusEnum')->willReturn($roomStatus ?? RoomStatus::CLEANING);
        $room->method('setStatusEnum');

        $task = new CleaningTask();

        $ref = new \ReflectionProperty(CleaningTask::class, 'id');
        $ref->setValue($task, Uuid::v4());

        $refRoom = new \ReflectionProperty(CleaningTask::class, 'room');
        $refRoom->setValue($task, $room);

        $task->setStatusEnum($status);
        $task->setTypeEnum(CleaningType::DEPARTURE);
        $task->setScheduledAt(new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar')));

        return $task;
    }

    // ── Test 1 : INSPECTED requiert DONE ──

    public function testInspectedRequiresDone(): void
    {
        $task = $this->makeTask(CleaningStatus::IN_PROGRESS);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('terminée');

        $this->service->updateStatus($task, CleaningStatus::INSPECTED, $this->staff);
    }

    // ── Test 2 : INSPECTED est terminal ──

    public function testInspectedIsTerminal(): void
    {
        $task = $this->makeTask(CleaningStatus::INSPECTED);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessage('inspectée');

        $this->service->updateStatus($task, CleaningStatus::PENDING, $this->staff);
    }

    // ── Test 3 : DONE libère la chambre CLEANING → AVAILABLE ──

    public function testDoneReleasesCleaningRoom(): void
    {
        $room = $this->createMock(Room::class);
        $room->method('getId')->willReturn(Uuid::v4());
        $room->method('getNumber')->willReturn('205');
        $room->method('getStatusEnum')->willReturn(RoomStatus::CLEANING);
        $room->expects($this->once())->method('setStatusEnum')->with(RoomStatus::AVAILABLE);

        $task = new CleaningTask();
        $ref = new \ReflectionProperty(CleaningTask::class, 'id');
        $ref->setValue($task, Uuid::v4());
        $refRoom = new \ReflectionProperty(CleaningTask::class, 'room');
        $refRoom->setValue($task, $room);
        $task->setStatusEnum(CleaningStatus::IN_PROGRESS);
        $task->setTypeEnum(CleaningType::DEPARTURE);
        $task->setScheduledAt(new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar')));

        $this->service->updateStatus($task, CleaningStatus::DONE, $this->staff);
    }

    // ── Test 4 : IN_PROGRESS met startedAt ──

    public function testInProgressSetsStartedAt(): void
    {
        $task = $this->makeTask(CleaningStatus::PENDING);

        $this->assertNull($task->getStartedAt());

        $result = $this->service->updateStatus($task, CleaningStatus::IN_PROGRESS, $this->staff);

        $this->assertNotNull($result->getStartedAt());
    }

    // ── Test 5 : DONE met completedAt ──

    public function testDoneSetsCompletedAt(): void
    {
        $task = $this->makeTask(CleaningStatus::IN_PROGRESS);

        $this->assertNull($task->getCompletedAt());

        $result = $this->service->updateStatus($task, CleaningStatus::DONE, $this->staff);

        $this->assertNotNull($result->getCompletedAt());
    }

    // ── Test 6 : SKIPPED → PENDING autorisé ──

    public function testSkippedToPendingAllowed(): void
    {
        $task = $this->makeTask(CleaningStatus::SKIPPED);

        $result = $this->service->updateStatus($task, CleaningStatus::PENDING, $this->staff);

        $this->assertEquals(CleaningStatus::PENDING, $result->getStatusEnum());
    }
}
