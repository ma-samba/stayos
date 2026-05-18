<?php

namespace App\Hotel\Room\Domain\Service;

use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;

class RoomService
{
    public function __construct(
        private readonly RoomRepository         $roomRepository,
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
}
