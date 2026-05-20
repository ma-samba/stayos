<?php

namespace App\Hotel\Reservation\Domain\Service;

use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Shared\Exception\ConflictException;

class ConflictChecker
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
    ) {}

    public function isAvailable(
        string              $roomId,
        \DateTimeImmutable  $checkIn,
        \DateTimeImmutable  $checkOut,
        ?string             $excludeReservationId = null,
    ): bool {
        if ($checkIn >= $checkOut) {
            throw new \InvalidArgumentException(
                'La date d\'arrivée doit être antérieure à la date de départ.'
            );
        }

        return $this->reservationRepository->isRoomAvailable(
            $roomId,
            $checkIn,
            $checkOut,
            $excludeReservationId,
        );
    }

    public function assertAvailable(
        string              $roomId,
        \DateTimeImmutable  $checkIn,
        \DateTimeImmutable  $checkOut,
        ?string             $excludeReservationId = null,
    ): void {
        if (!$this->isAvailable($roomId, $checkIn, $checkOut, $excludeReservationId)) {
            throw new ConflictException('Cette chambre est déjà réservée pour ces dates.');
        }
    }
}
