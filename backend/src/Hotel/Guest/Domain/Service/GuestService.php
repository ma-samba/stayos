<?php

namespace App\Hotel\Guest\Domain\Service;

use App\Hotel\Guest\Application\DTO\CreateGuestDTO;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\AlreadyExistsException;
use Doctrine\ORM\EntityManagerInterface;

class GuestService
{
    public function __construct(
        private readonly GuestRepository        $guestRepository,
        private readonly EntityManagerInterface  $entityManager,
        private readonly AuditService            $auditService,
    ) {}

    public function search(string $query): array
    {
        if (strlen(trim($query)) < 2) {
            return [];
        }

        return $this->guestRepository->searchByQuery(trim($query));
    }

    public function create(CreateGuestDTO $dto, ?StaffUser $staffUser): Guest
    {
        if ($dto->email) {
            $existing = $this->guestRepository->findOneBy(['email' => $dto->email]);
            if ($existing !== null) {
                throw new AlreadyExistsException(
                    sprintf('Un client existe déjà avec l\'email %s', $dto->email)
                );
            }
        }

        $guest = new Guest();
        $guest->setFirstName($dto->firstName);
        $guest->setLastName($dto->lastName);
        $guest->setEmail($dto->email);
        $guest->setPhone($dto->phone);
        $guest->setNationality($dto->nationality);
        $guest->setDocumentNumber($dto->documentNumber);

        $this->entityManager->persist($guest);

        $this->auditService->log(
            action:     'guest.created',
            entityType: 'Guest',
            entityId:   (string) $guest->getId(),
            before:     null,
            after:      [
                'firstName' => $guest->getFirstName(),
                'lastName'  => $guest->getLastName(),
                'email'     => $guest->getEmail(),
            ],
            staffUser:  $staffUser,
        );

        $this->entityManager->flush();

        return $guest;
    }
}
