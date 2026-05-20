<?php

namespace App\Hotel\Guest\Domain\Service;

use App\Hotel\Guest\Application\DTO\CreateGuestDTO;
use App\Hotel\Guest\Application\DTO\UpdateGuestDTO;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\AlreadyExistsException;
use Doctrine\ORM\EntityManagerInterface;

class GuestService
{
    public function __construct(
        private readonly GuestRepository         $guestRepository,
        private readonly ReservationRepository   $reservationRepository,
        private readonly EntityManagerInterface   $entityManager,
        private readonly AuditService             $auditService,
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

    public function findOrCreate(CreateGuestDTO $dto, ?StaffUser $staff): Guest
    {
        if ($dto->email) {
            $existing = $this->guestRepository->findOneBy(['email' => $dto->email]);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->create($dto, $staff);
    }

    public function update(Guest $guest, UpdateGuestDTO $dto, ?StaffUser $staff): Guest
    {
        $before = [
            'firstName' => $guest->getFirstName(),
            'lastName'  => $guest->getLastName(),
            'email'     => $guest->getEmail(),
            'phone'     => $guest->getPhone(),
        ];

        if ($dto->firstName !== null)      { $guest->setFirstName($dto->firstName); }
        if ($dto->lastName !== null)       { $guest->setLastName($dto->lastName); }
        if ($dto->email !== null)          { $guest->setEmail($dto->email ?: null); }
        if ($dto->phone !== null)          { $guest->setPhone($dto->phone ?: null); }
        if ($dto->nationality !== null)    { $guest->setNationality($dto->nationality ?: null); }
        if ($dto->documentType !== null)   { $guest->setDocumentType($dto->documentType ?: null); }
        if ($dto->documentNumber !== null) { $guest->setDocumentNumber($dto->documentNumber ?: null); }
        if ($dto->documentUrl !== null)    { $guest->setDocumentUrl($dto->documentUrl ?: null); }
        if ($dto->address !== null)        { $guest->setAddress($dto->address ?: null); }
        if ($dto->city !== null)           { $guest->setCity($dto->city ?: null); }
        if ($dto->country !== null)        { $guest->setCountry($dto->country ?: null); }

        $this->auditService->log(
            action:     'guest.updated',
            entityType: 'Guest',
            entityId:   (string) $guest->getId(),
            before:     $before,
            after:      [
                'firstName' => $guest->getFirstName(),
                'lastName'  => $guest->getLastName(),
                'email'     => $guest->getEmail(),
                'phone'     => $guest->getPhone(),
            ],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        return $guest;
    }

    public function getStayHistory(Guest $guest): array
    {
        return $this->reservationRepository->findBy(
            ['guest' => $guest],
            ['checkIn' => 'DESC'],
        );
    }
}
