<?php

namespace App\Hotel\Property\Domain\Service;

use App\Hotel\Property\Application\DTO\CreateFloorDTO;
use App\Hotel\Property\Application\DTO\UpdateFloorDTO;
use App\Hotel\Property\Domain\Entity\Floor;
use App\Hotel\Property\Infrastructure\Repository\FloorRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sprint 13ter — Gestion des étages d'un hôtel. Toute la logique
 * métier (audit, unicité du numéro, blocage de suppression si
 * chambres liées) est ici, le controller reste mince.
 */
class FloorService
{
    public function __construct(
        private readonly FloorRepository        $floorRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditService           $auditService,
    ) {}

    public function create(CreateFloorDTO $dto, ?StaffUser $staffUser): Floor
    {
        if ($this->floorRepository->existsByNumber((int) $dto->number)) {
            throw new AlreadyExistsException(sprintf(
                "Un étage avec le numéro %d existe déjà.",
                $dto->number,
            ));
        }

        $floor = new Floor();
        $floor->setNumber((int) $dto->number);
        $floor->setName($dto->name);

        $this->entityManager->persist($floor);

        $this->auditService->log(
            action:     'floor.created',
            entityType: 'Floor',
            entityId:   (string) $floor->getId(),
            before:     null,
            after:      ['number' => $floor->getNumber(), 'name' => $floor->getName()],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $floor;
    }

    public function update(Floor $floor, UpdateFloorDTO $dto, ?StaffUser $staffUser): Floor
    {
        $before = [
            'number' => $floor->getNumber(),
            'name'   => $floor->getName(),
            'active' => $floor->isActive(),
        ];

        if ($dto->number !== null && $dto->number !== $floor->getNumber()) {
            if ($this->floorRepository->existsByNumber($dto->number, (string) $floor->getId())) {
                throw new AlreadyExistsException(sprintf(
                    "Un étage avec le numéro %d existe déjà.",
                    $dto->number,
                ));
            }
            $floor->setNumber($dto->number);
        }

        if ($dto->name !== null) {
            $floor->setName($dto->name !== '' ? $dto->name : null);
        }

        $this->auditService->log(
            action:     'floor.updated',
            entityType: 'Floor',
            entityId:   (string) $floor->getId(),
            before:     $before,
            after:      [
                'number' => $floor->getNumber(),
                'name'   => $floor->getName(),
                'active' => $floor->isActive(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $floor;
    }

    public function delete(Floor $floor, ?StaffUser $staffUser): void
    {
        $count = $this->floorRepository->countRoomsOnFloor($floor);
        if ($count > 0) {
            $numbers = $this->floorRepository->getRoomNumbersOnFloor($floor);
            throw new BusinessRuleException(sprintf(
                "L'étage contient %d chambre(s) (%s%s). Détachez-les ou supprimez-les d'abord.",
                $count,
                implode(', ', $numbers),
                $count > count($numbers) ? '…' : '',
            ));
        }

        $this->auditService->log(
            action:     'floor.deleted',
            entityType: 'Floor',
            entityId:   (string) $floor->getId(),
            before:     [
                'number' => $floor->getNumber(),
                'name'   => $floor->getName(),
                'active' => $floor->isActive(),
            ],
            after:      null,
            staffUser:  $staffUser,
        );

        $this->entityManager->remove($floor);
        $this->entityManager->flush();
    }

    public function deactivate(Floor $floor, ?StaffUser $staffUser): Floor
    {
        if (!$floor->isActive()) {
            return $floor;
        }

        $floor->setActive(false);

        $this->auditService->log(
            action:     'floor.deactivated',
            entityType: 'Floor',
            entityId:   (string) $floor->getId(),
            before:     ['active' => true],
            after:      ['active' => false],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();
        return $floor;
    }

    public function reactivate(Floor $floor, ?StaffUser $staffUser): Floor
    {
        if ($floor->isActive()) {
            return $floor;
        }

        $floor->setActive(true);

        $this->auditService->log(
            action:     'floor.reactivated',
            entityType: 'Floor',
            entityId:   (string) $floor->getId(),
            before:     ['active' => false],
            after:      ['active' => true],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();
        return $floor;
    }
}
