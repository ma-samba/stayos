<?php

namespace App\Tests\Unit\Service;

use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Domain\Service\RoomService;
use App\Hotel\Property\Infrastructure\Repository\FloorRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use ReflectionClass;

class RoomServiceTest extends TestCase
{
    private RoomService $service;

    /** @var RoomRepository&MockObject */
    private RoomRepository $roomRepository;

    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $entityManager;

    /** @var AuditService&MockObject */
    private AuditService $auditService;

    /** @var MercurePublisher&MockObject */
    private MercurePublisher $mercurePublisher;

    protected function setUp(): void
    {
        $this->roomRepository   = $this->createMock(RoomRepository::class);
        $this->entityManager    = $this->createMock(EntityManagerInterface::class);
        $this->auditService     = $this->createMock(AuditService::class);
        $this->mercurePublisher = $this->createMock(MercurePublisher::class);

        $this->service = new RoomService(
            $this->roomRepository,
            $this->createMock(RoomTypeRepository::class),
            $this->createMock(FloorRepository::class),
            $this->entityManager,
            $this->auditService,
            $this->mercurePublisher,
        );
    }

    public function testFindAllDelegatesToRepository(): void
    {
        $rooms = [$this->createMock(Room::class)];
        $this->roomRepository->expects(self::once())
            ->method('findAllWithTypeAndFloor')
            ->willReturn($rooms);

        $result = $this->service->findAll();

        self::assertSame($rooms, $result);
    }

    public function testFindAvailableDelegatesToRepository(): void
    {
        $checkIn  = new \DateTimeImmutable('2026-06-01');
        $checkOut = new \DateTimeImmutable('2026-06-05');
        $rooms    = [$this->createMock(Room::class)];

        $this->roomRepository->expects(self::once())
            ->method('findAvailable')
            ->with($checkIn, $checkOut, 2)
            ->willReturn($rooms);

        $result = $this->service->findAvailable($checkIn, $checkOut, 2);

        self::assertSame($rooms, $result);
    }

    public function testUpdateStatusChangesRoomStatus(): void
    {
        $room = $this->buildRoom(RoomStatus::AVAILABLE);

        $this->entityManager->expects(self::once())->method('flush');
        $this->auditService->expects(self::once())->method('log');
        $this->mercurePublisher->expects(self::once())->method('publish')
            ->with('room.status.changed', self::arrayHasKey('status'));

        $this->service->updateStatus($room, RoomStatus::CLEANING, null, null);

        self::assertSame('cleaning', $room->getStatus());
    }

    public function testUpdateStatusSetsNotesWhenProvided(): void
    {
        $room = $this->buildRoom(RoomStatus::AVAILABLE);

        $this->entityManager->expects(self::once())->method('flush');
        $this->auditService->expects(self::once())->method('log');
        $this->mercurePublisher->expects(self::once())->method('publish');

        $this->service->updateStatus($room, RoomStatus::MAINTENANCE, 'Robinet cassé', null);

        self::assertSame('maintenance', $room->getStatus());
        self::assertSame('Robinet cassé', $room->getNotes());
    }

    public function testUpdateStatusDoesNotOverwriteNotesWhenNull(): void
    {
        $room = $this->buildRoom(RoomStatus::AVAILABLE);
        $room->setNotes('Note existante');

        $this->entityManager->expects(self::once())->method('flush');
        $this->auditService->expects(self::once())->method('log');
        $this->mercurePublisher->expects(self::once())->method('publish');

        $this->service->updateStatus($room, RoomStatus::CLEANING, null, null);

        self::assertSame('Note existante', $room->getNotes());
    }

    public function testUpdateStatusAuditsWithCorrectData(): void
    {
        $room      = $this->buildRoom(RoomStatus::AVAILABLE);
        $staffUser = $this->createMock(StaffUser::class);

        $this->entityManager->expects(self::once())->method('flush');
        $this->mercurePublisher->expects(self::once())->method('publish');

        $this->auditService->expects(self::once())
            ->method('log')
            ->with(
                'room.status_changed',
                'Room',
                self::anything(),
                ['status' => 'available'],
                ['status' => 'occupied'],
                $staffUser,
            );

        $this->service->updateStatus($room, RoomStatus::OCCUPIED, null, $staffUser);
    }

    public function testUpdateStatusPublishesCorrectMercurePayload(): void
    {
        $room = $this->buildRoom(RoomStatus::AVAILABLE);

        $this->entityManager->expects(self::once())->method('flush');
        $this->auditService->expects(self::once())->method('log');

        $this->mercurePublisher->expects(self::once())
            ->method('publish')
            ->with(
                'room.status.changed',
                self::callback(function (array $payload): bool {
                    return isset($payload['roomId'], $payload['roomNumber'], $payload['status'])
                        && $payload['status'] === 'cleaning';
                }),
            );

        $this->service->updateStatus($room, RoomStatus::CLEANING, null, null);
    }

    // ──────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────

    private function buildRoom(RoomStatus $status): Room
    {
        $room = new Room();
        $room->setNumber('101');
        $room->setStatusEnum($status);

        // Initialise $id via réflexion (Doctrine le fait normalement)
        $ref = new ReflectionClass($room);
        $prop = $ref->getProperty('id');
        $prop->setAccessible(true);
        $prop->setValue($room, Uuid::v7());

        return $room;
    }
}
