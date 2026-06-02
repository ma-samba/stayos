<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Analytics\Infrastructure\Repository\AnalyticsRepository;
use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Notification\Domain\Service\OperationalAlertService;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Room\Domain\Entity\Room;
use App\Shared\Mercure\MercurePublisher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

class OperationalAlertServiceTest extends TestCase
{
    private OperationalAlertService $service;
    private MockObject&AnalyticsRepository $analyticsRepo;
    private MockObject&CleaningTaskRepository $cleaningTaskRepo;
    private MockObject&MercurePublisher $mercurePublisher;
    private MockObject&LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->analyticsRepo    = $this->createMock(AnalyticsRepository::class);
        $this->cleaningTaskRepo = $this->createMock(CleaningTaskRepository::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);
        $this->logger           = $this->createMock(LoggerInterface::class);

        $this->service = new OperationalAlertService(
            $this->analyticsRepo,
            $this->cleaningTaskRepo,
            $this->mercurePublisher,
            $this->logger,
        );
    }

    private function makeReservation(string $roomNumber = '101'): Reservation
    {
        $room = $this->createMock(Room::class);
        $room->method('getNumber')->willReturn($roomNumber);

        $guest = $this->createMock(\App\Hotel\Guest\Domain\Entity\Guest::class);
        $guest->method('getFullName')->willReturn('Amadou Diallo');

        $reservation = new Reservation();
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, Uuid::v4());
        $reservation->setRoom($room);
        $reservation->setGuest($guest);
        $reservation->setConfirmationNumber('RES-2026-00001');
        $reservation->setCheckIn(new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar')));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-05-28', new \DateTimeZone('Africa/Dakar')));

        return $reservation;
    }

    private function makeUnassignedTask(string $roomNumber = '201'): CleaningTask
    {
        $room = $this->createMock(Room::class);
        $room->method('getNumber')->willReturn($roomNumber);

        $task = new CleaningTask();
        $ref = new \ReflectionProperty(CleaningTask::class, 'id');
        $ref->setValue($task, Uuid::v4());
        $task->setRoom($room);
        $task->setType('departure');
        // assignedTo is null by default

        return $task;
    }

    // ── Test 1 : Arrivals published ──

    public function testPublishDailyAlertsWithArrivals(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('arrivalsForDay')
            ->with($day)
            ->willReturn([$this->makeReservation('312'), $this->makeReservation('205')]);

        $this->cleaningTaskRepo->method('findForBoard')->willReturn([]);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'alert.arrivals_today',
                $this->callback(function (array $data): bool {
                    return $data['count'] === 2
                        && \count($data['arrivals']) === 2;
                }),
            );

        $stats = $this->service->publishDailyAlerts($day);

        $this->assertEquals(2, $stats['arrivals']);
        $this->assertEquals(0, $stats['unassignedTasks']);
    }

    // ── Test 2 : Unassigned tasks published ──

    public function testPublishDailyAlertsWithUnassignedTasks(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('arrivalsForDay')->willReturn([]);

        $task = $this->makeUnassignedTask('301');
        $this->cleaningTaskRepo->method('findForBoard')->willReturn([$task]);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'alert.unassigned_tasks',
                $this->callback(function (array $data): bool {
                    return $data['count'] === 1
                        && $data['tasks'][0]['room'] === '301';
                }),
            );

        $stats = $this->service->publishDailyAlerts($day);

        $this->assertEquals(0, $stats['arrivals']);
        $this->assertEquals(1, $stats['unassignedTasks']);
    }

    // ── Test 3 : No alerts when nothing to report ──

    public function testPublishDailyAlertsNoOp(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('arrivalsForDay')->willReturn([]);
        $this->cleaningTaskRepo->method('findForBoard')->willReturn([]);

        $this->mercurePublisher->expects($this->never())->method('publish');

        $stats = $this->service->publishDailyAlerts($day);

        $this->assertEquals(0, $stats['arrivals']);
        $this->assertEquals(0, $stats['unassignedTasks']);
    }

    // ── Test 4 : Late checkouts published ──

    public function testCheckLateCheckouts(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('departuresForDay')
            ->with($day)
            ->willReturn([$this->makeReservation('101')]);

        $this->mercurePublisher
            ->expects($this->once())
            ->method('publish')
            ->with(
                'alert.late_checkout',
                $this->callback(function (array $data): bool {
                    return $data['count'] === 1
                        && $data['checkouts'][0]['room'] === '101';
                }),
            );

        $count = $this->service->checkLateCheckouts($day);

        $this->assertEquals(1, $count);
    }

    // ── Test 5 : No late checkouts ──

    public function testCheckLateCheckoutsNone(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('departuresForDay')->willReturn([]);

        $this->mercurePublisher->expects($this->never())->method('publish');

        $count = $this->service->checkLateCheckouts($day);

        $this->assertEquals(0, $count);
    }

    // ── Test 6 : Assigned tasks are NOT included in unassigned count ──

    public function testAssignedTasksNotReportedAsUnassigned(): void
    {
        $day = new \DateTimeImmutable('2026-05-25', new \DateTimeZone('Africa/Dakar'));

        $this->analyticsRepo->method('arrivalsForDay')->willReturn([]);

        $assignedTask = $this->makeUnassignedTask('301');
        // Simulate assigned task: set assignedTo
        $staff = $this->createMock(\App\Platform\Auth\Domain\Entity\StaffUser::class);
        $assignedTask->setAssignedTo($staff);

        $this->cleaningTaskRepo->method('findForBoard')->willReturn([$assignedTask]);

        $this->mercurePublisher->expects($this->never())->method('publish');

        $stats = $this->service->publishDailyAlerts($day);

        $this->assertEquals(0, $stats['unassignedTasks']);
    }
}
