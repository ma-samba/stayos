<?php

namespace App\Hotel\Room\Domain\Service;

use App\Hotel\Property\Infrastructure\Repository\FloorRepository;
use App\Hotel\Room\Application\DTO\UpdateRoomDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomTypeDTO;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\ConflictException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;

class RoomService
{
    public function __construct(
        private readonly RoomRepository         $roomRepository,
        private readonly RoomTypeRepository     $roomTypeRepository,
        private readonly FloorRepository        $floorRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditService           $auditService,
        private readonly MercurePublisher       $mercurePublisher,
    ) {}

    /**
     * Retourne toutes les chambres actives avec leur type et étage.
     */
    public function findAll(): array
    {
        return $this->roomRepository->findAllWithTypeAndFloor();
    }

    /**
     * Retourne les chambres disponibles pour une période et un nombre d'adultes.
     */
    public function findAvailable(\DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, int $adults): array
    {
        return $this->roomRepository->findAvailable($checkIn, $checkOut, $adults);
    }

    /**
     * Change le statut d'une chambre, audite l'action et publie sur Mercure.
     */
    public function updateStatus(Room $room, RoomStatus $newStatus, ?string $notes, ?StaffUser $staffUser): void
    {
        $oldStatus = $room->getStatus();

        $room->setStatusEnum($newStatus);

        if ($notes !== null) {
            $room->setNotes($notes);
        }

        $this->auditService->log(
            action:     'room.status_changed',
            entityType: 'Room',
            entityId:   (string) $room->getId(),
            before:     ['status' => $oldStatus],
            after:      ['status' => $newStatus->value],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('room.status.changed', [
            'roomId'     => (string) $room->getId(),
            'roomNumber' => $room->getNumber(),
            'status'     => $newStatus->value,
        ]);
    }

    public function updateRoom(Room $room, UpdateRoomDTO $dto, ?StaffUser $staffUser): Room
    {
        $before = [
            'number'   => $room->getNumber(),
            'typeId'   => (string) $room->getType()->getId(),
            'floorId'  => $room->getFloor()?->getId() ? (string) $room->getFloor()->getId() : null,
            'notes'    => $room->getNotes(),
            'isActive' => $room->isActive(),
        ];

        if ($dto->number !== null)   { $room->setNumber($dto->number); }
        if ($dto->notes !== null)    { $room->setNotes($dto->notes); }
        if ($dto->isActive !== null) { $room->setIsActive($dto->isActive); }

        if ($dto->typeId !== null) {
            $type = $this->roomTypeRepository->find($dto->typeId);
            if ($type === null) {
                throw new ConflictException('Type de chambre introuvable');
            }
            $room->setType($type);
        }

        if ($dto->floorId !== null) {
            $floor = $this->floorRepository->find($dto->floorId);
            $room->setFloor($floor);
        }

        $this->auditService->log(
            action:     'room.updated',
            entityType: 'Room',
            entityId:   (string) $room->getId(),
            before:     $before,
            after:      [
                'number'   => $room->getNumber(),
                'typeId'   => (string) $room->getType()->getId(),
                'notes'    => $room->getNotes(),
                'isActive' => $room->isActive(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $room;
    }

    public function updateType(RoomType $type, UpdateRoomTypeDTO $dto, ?StaffUser $staffUser): RoomType
    {
        $before = [
            'name'         => $type->getName(),
            'baseRateXof'  => $type->getBaseRateXof(),
            'maxOccupancy' => $type->getMaxOccupancy(),
        ];

        if ($dto->name !== null)         { $type->setName($dto->name); }
        if ($dto->baseRateXof !== null)  { $type->setBaseRateXof($dto->baseRateXof); }
        if ($dto->maxOccupancy !== null) { $type->setMaxOccupancy($dto->maxOccupancy); }
        if ($dto->description !== null)  { $type->setDescription($dto->description); }

        $this->auditService->log(
            action:     'room_type.updated',
            entityType: 'RoomType',
            entityId:   (string) $type->getId(),
            before:     $before,
            after:      [
                'name'         => $type->getName(),
                'baseRateXof'  => $type->getBaseRateXof(),
                'maxOccupancy' => $type->getMaxOccupancy(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $type;
    }
}
