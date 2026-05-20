<?php

namespace App\Hotel\Reservation\Domain\Service;

use App\Hotel\Billing\Domain\Service\InvoiceDraftService;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Reservation\Application\DTO\CreateReservationDTO;
use App\Hotel\Reservation\Application\DTO\UpdateReservationDTO;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReservationEngine
{
    public function __construct(
        private readonly ReservationRepository  $reservationRepository,
        private readonly RoomRepository         $roomRepository,
        private readonly GuestRepository        $guestRepository,
        private readonly ConflictChecker        $conflictChecker,
        private readonly AuditService           $auditService,
        private readonly MercurePublisher       $mercurePublisher,
        private readonly InvoiceDraftService    $invoiceDraftService,
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function create(CreateReservationDTO $dto, ?StaffUser $staff): Reservation
    {
        $room = $this->roomRepository->find($dto->roomId)
            ?? throw new NotFoundHttpException('Chambre introuvable.');

        $guest = $this->guestRepository->find($dto->guestId)
            ?? throw new NotFoundHttpException('Client introuvable.');

        $tz = new \DateTimeZone('Africa/Dakar');
        $checkIn = new \DateTimeImmutable($dto->checkIn, $tz);
        $checkOut = new \DateTimeImmutable($dto->checkOut, $tz);

        $this->conflictChecker->assertAvailable((string) $room->getId(), $checkIn, $checkOut);

        $nights = (int) $checkIn->diff($checkOut)->days;
        $rateXof = $room->getType()->getBaseRateXof();
        $totalXof = number_format((float) $rateXof * $nights, 2, '.', '');

        $reservation = new Reservation();
        $reservation
            ->setConfirmationNumber($this->reservationRepository->generateConfirmationNumber())
            ->setRoom($room)
            ->setGuest($guest)
            ->setCheckIn($checkIn)
            ->setCheckOut($checkOut)
            ->setAdults($dto->adults)
            ->setChildren($dto->children)
            ->setRateXof($rateXof)
            ->setTotalXof($totalXof)
            ->setSource($dto->source)
            ->setStatusEnum(ReservationStatus::CONFIRMED);

        if ($dto->notes !== null) {
            $reservation->setNotes($dto->notes);
        }
        if ($dto->specialRequests !== null) {
            $reservation->setSpecialRequests($dto->specialRequests);
        }
        if ($dto->depositXof !== null) {
            $reservation->setDepositXof($dto->depositXof);
        }

        $this->entityManager->persist($reservation);

        $this->auditService->log(
            action:     'reservation.created',
            entityType: 'Reservation',
            entityId:   'new',
            after:      [
                'confirmationNumber' => $reservation->getConfirmationNumber(),
                'room'               => $room->getNumber(),
                'guest'              => $guest->getFullName(),
                'checkIn'            => $dto->checkIn,
                'checkOut'           => $dto->checkOut,
                'totalXof'           => $totalXof,
            ],
            staffUser: $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.created', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $room->getNumber(),
            'guest'              => $guest->getFullName(),
            'checkIn'            => $dto->checkIn,
            'checkOut'           => $dto->checkOut,
        ]);

        return $reservation;
    }

    public function update(Reservation $reservation, UpdateReservationDTO $dto, ?StaffUser $staff): Reservation
    {
        $blockedStatuses = [
            ReservationStatus::CHECKED_IN,
            ReservationStatus::CHECKED_OUT,
            ReservationStatus::CANCELLED,
        ];
        if (in_array($reservation->getStatusEnum(), $blockedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible de modifier une réservation avec le statut "%s".',
                $reservation->getStatus()
            ));
        }

        $before = [
            'room'     => $reservation->getRoom()->getNumber(),
            'checkIn'  => $reservation->getCheckIn()->format('Y-m-d'),
            'checkOut' => $reservation->getCheckOut()->format('Y-m-d'),
            'adults'   => $reservation->getAdults(),
        ];

        $tz = new \DateTimeZone('Africa/Dakar');
        $checkIn  = $dto->checkIn  !== null ? new \DateTimeImmutable($dto->checkIn, $tz) : $reservation->getCheckIn();
        $checkOut = $dto->checkOut !== null ? new \DateTimeImmutable($dto->checkOut, $tz) : $reservation->getCheckOut();

        $room = $reservation->getRoom();
        if ($dto->roomId !== null) {
            $room = $this->roomRepository->find($dto->roomId)
                ?? throw new NotFoundHttpException('Chambre introuvable.');
        }

        // Vérifier les conflits si dates ou chambre changent
        if ($dto->roomId !== null || $dto->checkIn !== null || $dto->checkOut !== null) {
            $this->conflictChecker->assertAvailable(
                (string) $room->getId(),
                $checkIn,
                $checkOut,
                (string) $reservation->getId(),
            );
        }

        if ($dto->roomId !== null) {
            $reservation->setRoom($room);
        }
        if ($dto->guestId !== null) {
            $guest = $this->guestRepository->find($dto->guestId)
                ?? throw new NotFoundHttpException('Client introuvable.');
            $reservation->setGuest($guest);
        }
        if ($dto->checkIn !== null) {
            $reservation->setCheckIn($checkIn);
        }
        if ($dto->checkOut !== null) {
            $reservation->setCheckOut($checkOut);
        }
        if ($dto->adults !== null) {
            $reservation->setAdults($dto->adults);
        }
        if ($dto->children !== null) {
            $reservation->setChildren($dto->children);
        }
        if ($dto->notes !== null) {
            $reservation->setNotes($dto->notes);
        }
        if ($dto->specialRequests !== null) {
            $reservation->setSpecialRequests($dto->specialRequests);
        }
        if ($dto->source !== null) {
            $reservation->setSource($dto->source);
        }
        if ($dto->depositXof !== null) {
            $reservation->setDepositXof($dto->depositXof);
        }

        // Recalculer le total si dates ou chambre changent
        if ($dto->roomId !== null || $dto->checkIn !== null || $dto->checkOut !== null) {
            $nights = (int) $reservation->getCheckIn()->diff($reservation->getCheckOut())->days;
            $rateXof = $reservation->getRoom()->getType()->getBaseRateXof();
            $reservation->setRateXof($rateXof);
            $reservation->setTotalXof(number_format((float) $rateXof * $nights, 2, '.', ''));
        }

        $this->auditService->log(
            action:     'reservation.updated',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     $before,
            after:      [
                'room'     => $reservation->getRoom()->getNumber(),
                'checkIn'  => $reservation->getCheckIn()->format('Y-m-d'),
                'checkOut' => $reservation->getCheckOut()->format('Y-m-d'),
                'adults'   => $reservation->getAdults(),
            ],
            staffUser: $staff,
        );

        $this->entityManager->flush();

        return $reservation;
    }

    public function cancel(Reservation $reservation, string $reason, ?StaffUser $staff): Reservation
    {
        $blockedStatuses = [ReservationStatus::CHECKED_IN, ReservationStatus::CHECKED_OUT];
        if (in_array($reservation->getStatusEnum(), $blockedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'annuler une réservation avec le statut "%s".',
                $reservation->getStatus()
            ));
        }

        $beforeStatus = $reservation->getStatus();

        $reservation->setStatusEnum(ReservationStatus::CANCELLED);
        $reservation->setNotes(
            ($reservation->getNotes() ? $reservation->getNotes() . "\n" : '')
            . '[Annulation] ' . $reason
        );

        // Libérer la chambre si elle était occupée
        if ($beforeStatus === ReservationStatus::CHECKED_IN->value) {
            $reservation->getRoom()->setStatusEnum(RoomStatus::AVAILABLE);
        }

        $this->auditService->log(
            action:     'reservation.cancelled',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => $beforeStatus],
            after:      ['status' => ReservationStatus::CANCELLED->value, 'reason' => $reason],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        return $reservation;
    }

    public function checkIn(Reservation $reservation, ?string $notes, ?StaffUser $staff): Reservation
    {
        $allowedStatuses = [ReservationStatus::CONFIRMED, ReservationStatus::PENDING];
        if (!in_array($reservation->getStatusEnum(), $allowedStatuses, true)) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'enregistrer l\'arrivée : statut actuel "%s" non autorisé.',
                $reservation->getStatus()
            ));
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $reservation->setStatusEnum(ReservationStatus::CHECKED_IN);
        $reservation->setCheckedInAt($now);
        if ($notes !== null) {
            $reservation->setNotes(
                ($reservation->getNotes() ? $reservation->getNotes() . "\n" : '')
                . '[Check-in] ' . $notes
            );
        }

        // Passer la chambre en occupée
        $reservation->getRoom()->setStatusEnum(RoomStatus::OCCUPIED);

        // Créer une tâche ménage départ programmée pour le check-out
        $task = new CleaningTask();
        $task->setRoom($reservation->getRoom());
        $task->setType(CleaningType::DEPARTURE->value);
        $task->setScheduledAt($reservation->getCheckOut());
        $this->entityManager->persist($task);

        $this->auditService->log(
            action:     'reservation.checkin',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => ReservationStatus::CONFIRMED->value],
            after:      ['status' => ReservationStatus::CHECKED_IN->value, 'checkedInAt' => $now->format('c')],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.checkin', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
        ]);

        return $reservation;
    }

    public function checkOut(Reservation $reservation, ?StaffUser $staff): Reservation
    {
        if ($reservation->getStatusEnum() !== ReservationStatus::CHECKED_IN) {
            throw new BusinessRuleException(sprintf(
                'Impossible d\'enregistrer le départ : statut actuel "%s" non autorisé.',
                $reservation->getStatus()
            ));
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $reservation->setStatusEnum(ReservationStatus::CHECKED_OUT);
        $reservation->setCheckedOutAt($now);

        // Passer la chambre en ménage
        $reservation->getRoom()->setStatusEnum(RoomStatus::CLEANING);

        // Incrémenter les séjours du client
        $guest = $reservation->getGuest();
        $guest->setTotalStays($guest->getTotalStays() + 1);

        $this->auditService->log(
            action:     'reservation.checkout',
            entityType: 'Reservation',
            entityId:   (string) $reservation->getId(),
            before:     ['status' => ReservationStatus::CHECKED_IN->value],
            after:      ['status' => ReservationStatus::CHECKED_OUT->value, 'checkedOutAt' => $now->format('c')],
            staffUser:  $staff,
        );

        $this->entityManager->flush();

        $this->mercurePublisher->publish('reservation.checkout', [
            'id'                 => (string) $reservation->getId(),
            'confirmationNumber' => $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
        ]);

        // Facture draft générée APRÈS le check-out (isolée et
        // non-bloquante : un échec facture n'annule pas le check-out)
        try {
            $this->invoiceDraftService->createFromReservation($reservation);
        } catch (\Throwable $e) {
            $this->logger->error('Échec génération facture draft au check-out', [
                'reservation' => (string) $reservation->getId(),
                'error'       => $e->getMessage(),
            ]);
        }

        return $reservation;
    }
}
