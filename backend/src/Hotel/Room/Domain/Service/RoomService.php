<?php

namespace App\Hotel\Room\Domain\Service;

use App\Hotel\Property\Infrastructure\Repository\FloorRepository;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Application\DTO\BulkCreateRoomsDTO;
use App\Hotel\Room\Application\DTO\CreateRoomDTO;
use App\Hotel\Room\Application\DTO\CreateRoomTypeDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomTypeDTO;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Domain\Service\SubscriptionLimitChecker;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Exception\ConflictException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;

class RoomService
{
    public function __construct(
        private readonly RoomRepository           $roomRepository,
        private readonly RoomTypeRepository       $roomTypeRepository,
        private readonly FloorRepository          $floorRepository,
        private readonly ReservationRepository    $reservationRepository,
        private readonly SubscriptionLimitChecker $limitChecker,
        private readonly EntityManagerInterface   $entityManager,
        private readonly AuditService             $auditService,
        private readonly MercurePublisher         $mercurePublisher,
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

    /**
     * Crée une chambre unitaire (POST /api/rooms). Sprint 13ter.
     */
    public function createRoom(CreateRoomDTO $dto, ?StaffUser $staffUser): Room
    {
        $this->limitChecker->assertCanAddRoom();

        $type = $this->roomTypeRepository->find((string) $dto->typeId);
        if ($type === null) {
            throw new ConflictException('Type de chambre introuvable.');
        }

        $floor = null;
        if ($dto->floorId !== null && $dto->floorId !== '') {
            $floor = $this->floorRepository->find($dto->floorId);
            if ($floor === null) {
                throw new ConflictException('Étage introuvable.');
            }
        }

        if ($this->roomRepository->findOneBy(['number' => $dto->number]) !== null) {
            throw new AlreadyExistsException(sprintf(
                "Une chambre portant le numéro '%s' existe déjà.",
                $dto->number,
            ));
        }

        $room = new Room();
        $room->setType($type);
        $room->setFloor($floor);
        $room->setNumber((string) $dto->number);
        $room->setStatusEnum(RoomStatus::AVAILABLE);
        if ($dto->notes !== null && $dto->notes !== '') {
            $room->setNotes($dto->notes);
        }
        if ($dto->isActive !== null) {
            $room->setIsActive($dto->isActive);
        }

        $this->entityManager->persist($room);

        $this->auditService->log(
            action:     'room.created',
            entityType: 'Room',
            entityId:   (string) $room->getId(),
            before:     null,
            after:      [
                'number'   => $room->getNumber(),
                'typeId'   => (string) $type->getId(),
                'floorId'  => $floor !== null ? (string) $floor->getId() : null,
                'isActive' => $room->isActive(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $room;
    }

    /**
     * Création en lot (POST /api/rooms/bulk). Sprint 13ter.
     * Toute la séquence est exécutée dans une transaction unique :
     * si la N-ième chambre fait sauter la limite plan ou dépasse
     * un numéro déjà pris, on rollback complètement — pas de
     * création partielle.
     *
     * Génère un SEUL audit log « room.created_bulk » avec la
     * liste des numéros.
     *
     * @return Room[] Chambres créées (jamais partiel : succès complet ou exception)
     */
    public function bulkCreateRooms(BulkCreateRoomsDTO $dto, ?StaffUser $staffUser): array
    {
        $type = $this->roomTypeRepository->find((string) $dto->typeId);
        if ($type === null) {
            throw new ConflictException('Type de chambre introuvable.');
        }

        $floor = $this->floorRepository->find((string) $dto->floorId);
        if ($floor === null) {
            throw new ConflictException('Étage introuvable.');
        }

        // Construction de la liste des numéros + check d'unicité avant transaction
        $numbers = [];
        for ($i = 0; $i < (int) $dto->count; $i++) {
            $n = (int) $dto->startNumber + $i;
            $numbers[] = ($dto->prefix !== null ? $dto->prefix : '') . (string) $n;
        }

        foreach ($numbers as $num) {
            if ($this->roomRepository->findOneBy(['number' => $num]) !== null) {
                throw new AlreadyExistsException(sprintf(
                    "Une chambre portant le numéro '%s' existe déjà — création en lot annulée.",
                    $num,
                ));
            }
        }

        $created = [];
        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();

        try {
            foreach ($numbers as $num) {
                // Check limite pour CHAQUE chambre — sinon on peut dépasser
                // la limite plan par mass-create. assertCanAddRoom() recompte
                // à chaque itération (la chambre persistée précédemment compte).
                $this->limitChecker->assertCanAddRoom();

                $room = new Room();
                $room->setType($type);
                $room->setFloor($floor);
                $room->setNumber($num);
                $room->setStatusEnum(RoomStatus::AVAILABLE);

                $this->entityManager->persist($room);
                $this->entityManager->flush(); // flush par room pour que le count subséquent voie cette ligne
                $created[] = $room;
            }

            $this->auditService->log(
                action:     'room.created_bulk',
                entityType: 'Room',
                entityId:   (string) $floor->getId(), // ancrage logique : étage
                before:     null,
                after:      [
                    'floorId' => (string) $floor->getId(),
                    'typeId'  => (string) $type->getId(),
                    'count'   => count($created),
                    'range'   => count($numbers) > 0
                        ? sprintf('%s..%s', reset($numbers), end($numbers))
                        : null,
                ],
                staffUser:  $staffUser,
            );
            $this->entityManager->flush();

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            // Détacher les entités persistées du gestionnaire pour ne pas
            // contaminer un éventuel ré-essai.
            foreach ($created as $room) {
                $this->entityManager->detach($room);
            }
            throw $e;
        }

        return $created;
    }

    /**
     * Soft delete : isActive=false. Bloqué si réservations
     * actives (CONFIRMED/CHECKED_IN). Sprint 13ter.
     */
    public function softDelete(Room $room, ?StaffUser $staffUser): void
    {
        $blocking = $this->reservationRepository->findBlockingForRoom((string) $room->getId());
        if (count($blocking) > 0) {
            $refs = array_map(fn (array $r) => $r['confirmationNumber'], $blocking);
            throw new BusinessRuleException(sprintf(
                "Impossible de supprimer la chambre %s : %d réservation(s) active(s) (%s). "
                . "Annulez ou terminez ces réservations d'abord.",
                $room->getNumber(),
                count($blocking),
                implode(', ', $refs),
            ));
        }

        if (!$room->isActive()) {
            return;
        }

        $room->setIsActive(false);

        $this->auditService->log(
            action:     'room.deleted',
            entityType: 'Room',
            entityId:   (string) $room->getId(),
            before:     ['isActive' => true, 'number' => $room->getNumber()],
            after:      ['isActive' => false, 'number' => $room->getNumber()],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();
    }

    /**
     * Réactive une chambre soft-deleted. Check de la limite plan.
     */
    public function reactivate(Room $room, ?StaffUser $staffUser): Room
    {
        if ($room->isActive()) {
            return $room;
        }

        $this->limitChecker->assertCanAddRoom();

        $room->setIsActive(true);

        $this->auditService->log(
            action:     'room.reactivated',
            entityType: 'Room',
            entityId:   (string) $room->getId(),
            before:     ['isActive' => false],
            after:      ['isActive' => true],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $room;
    }

    public function createType(CreateRoomTypeDTO $dto, ?StaffUser $staffUser): RoomType
    {
        if ($this->roomTypeRepository->existsByNameCaseInsensitive((string) $dto->name)) {
            throw new AlreadyExistsException(sprintf(
                "Un type de chambre nommé '%s' existe déjà.",
                $dto->name,
            ));
        }

        $type = new RoomType();
        $type->setName((string) $dto->name);
        $type->setDescription($dto->description);
        $type->setBaseRateXof((string) $dto->baseRateXof);
        $type->setMaxOccupancy((int) $dto->maxOccupancy);
        $type->setBedConfiguration($dto->bedConfiguration);
        $type->setAmenities($dto->amenities);
        $type->setSortOrder($dto->sortOrder ?? $this->roomTypeRepository->getNextSortOrder());

        $this->entityManager->persist($type);

        $this->auditService->log(
            action:     'room_type.created',
            entityType: 'RoomType',
            entityId:   (string) $type->getId(),
            before:     null,
            after:      [
                'name'         => $type->getName(),
                'baseRateXof'  => $type->getBaseRateXof(),
                'maxOccupancy' => $type->getMaxOccupancy(),
                'sortOrder'    => $type->getSortOrder(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $type;
    }

    public function updateType(RoomType $type, UpdateRoomTypeDTO $dto, ?StaffUser $staffUser): RoomType
    {
        $before = [
            'name'         => $type->getName(),
            'baseRateXof'  => $type->getBaseRateXof(),
            'maxOccupancy' => $type->getMaxOccupancy(),
        ];

        if ($dto->name !== null && $dto->name !== $type->getName()) {
            if ($this->roomTypeRepository->existsByNameCaseInsensitive($dto->name, (string) $type->getId())) {
                throw new AlreadyExistsException(sprintf(
                    "Un type de chambre nommé '%s' existe déjà.",
                    $dto->name,
                ));
            }
            $type->setName($dto->name);
        }
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

    public function deleteType(RoomType $type, ?StaffUser $staffUser): void
    {
        $count = $this->roomTypeRepository->countRoomsOfType($type);
        if ($count > 0) {
            $numbers = $this->roomTypeRepository->getRoomNumbersOfType($type);
            throw new BusinessRuleException(sprintf(
                "Le type '%s' est utilisé par %d chambre(s) (%s%s). Réaffectez-les ou supprimez-les d'abord.",
                $type->getName(),
                $count,
                implode(', ', $numbers),
                $count > count($numbers) ? '…' : '',
            ));
        }

        $this->auditService->log(
            action:     'room_type.deleted',
            entityType: 'RoomType',
            entityId:   (string) $type->getId(),
            before:     [
                'name'         => $type->getName(),
                'baseRateXof'  => $type->getBaseRateXof(),
                'maxOccupancy' => $type->getMaxOccupancy(),
            ],
            after:      null,
            staffUser:  $staffUser,
        );

        $this->entityManager->remove($type);
        $this->entityManager->flush();
    }
}
