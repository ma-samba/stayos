<?php

declare(strict_types=1);

namespace App\Hotel\Housekeeping\Domain\Service;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

class HousekeepingService
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly AuditService            $auditService,
        private readonly MercurePublisher         $mercurePublisher,
        private readonly CleaningTaskRepository   $taskRepository,
        private readonly ReservationRepository    $reservationRepository,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    public function assign(CleaningTask $task, ?StaffUser $staff, ?StaffUser $actor): CleaningTask
    {
        $before = ['assignedTo' => $task->getAssignedTo()?->getFullName()];

        $task->setAssignedTo($staff);

        $after = ['assignedTo' => $staff?->getFullName()];

        $this->auditService->log(
            'cleaning_task.assigned',
            'CleaningTask',
            (string) $task->getId(),
            $before,
            $after,
            $actor,
        );

        $this->em->flush();

        $this->logger->info('Cleaning task assigned', [
            'task_id'     => (string) $task->getId(),
            'room'        => $task->getRoomNumber(),
            'assigned_to' => $staff?->getFullName(),
            'actor'       => $actor?->getFullName(),
        ]);

        // Mercure notification
        if ($staff !== null) {
            $this->mercurePublisher->publish('task.assigned', [
                'taskId'         => (string) $task->getId(),
                'roomNumber'     => $task->getRoomNumber(),
                'type'           => $task->getType(),
                'assignedToId'   => (string) $staff->getId(),
                'assignedToName' => $staff->getFullName(),
                'scheduledAt'    => $task->getScheduledAt()->format('c'),
            ]);
        }

        return $task;
    }

    public function updateStatus(CleaningTask $task, CleaningStatus $newStatus, ?StaffUser $actor): CleaningTask
    {
        $currentStatus = $task->getStatusEnum();

        // RULE 2: INSPECTED is terminal — cannot modify
        if ($currentStatus === CleaningStatus::INSPECTED) {
            throw new BusinessRuleException('Une tâche inspectée ne peut plus être modifiée.');
        }

        // RULE 1: INSPECTED requires DONE
        if ($newStatus === CleaningStatus::INSPECTED && $currentStatus !== CleaningStatus::DONE) {
            throw new BusinessRuleException('Seule une tâche terminée (done) peut être inspectée.');
        }

        $before = ['status' => $currentStatus->value];

        $task->setStatusEnum($newStatus);

        // Timestamp side effects
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        if ($newStatus === CleaningStatus::IN_PROGRESS && $task->getStartedAt() === null) {
            $task->setStartedAt($now);
        }

        if ($newStatus === CleaningStatus::DONE) {
            $task->setCompletedAt($now);
        }

        // RULE 3: DONE or INSPECTED on a CLEANING room → set room AVAILABLE
        if (
            in_array($newStatus, [CleaningStatus::DONE, CleaningStatus::INSPECTED], true)
            && $task->getRoom()->getStatusEnum() === RoomStatus::CLEANING
        ) {
            $task->getRoom()->setStatusEnum(RoomStatus::AVAILABLE);

            $this->logger->info('Room released after cleaning', [
                'room'    => $task->getRoomNumber(),
                'trigger' => $newStatus->value,
            ]);
        }

        $after = [
            'status'      => $newStatus->value,
            'startedAt'   => $task->getStartedAt()?->format('c'),
            'completedAt' => $task->getCompletedAt()?->format('c'),
        ];

        $this->auditService->log(
            'cleaning_task.status_changed',
            'CleaningTask',
            (string) $task->getId(),
            $before,
            $after,
            $actor,
        );

        $this->em->flush();

        $this->logger->info('Cleaning task status changed', [
            'task_id' => (string) $task->getId(),
            'room'    => $task->getRoomNumber(),
            'from'    => $currentStatus->value,
            'to'      => $newStatus->value,
            'actor'   => $actor?->getFullName(),
        ]);

        // Mercure notification
        $this->mercurePublisher->publish('task.updated', [
            'taskId'     => (string) $task->getId(),
            'roomNumber' => $task->getRoomNumber(),
            'status'     => $newStatus->value,
        ]);

        return $task;
    }

    /**
     * Generates daily STAY_OVER tasks for all rooms with an active stay.
     *
     * DEPARTURE tasks are NOT generated here (already created at check-in).
     * Idempotent: re-running for the same date creates no duplicates.
     *
     * @return int Number of tasks created
     */
    public function generateDailyTasks(?\DateTimeImmutable $date = null): int
    {
        $tz   = new \DateTimeZone('Africa/Dakar');
        $date = $date ?? new \DateTimeImmutable('today', $tz);

        // Find all CHECKED_IN reservations whose stay covers $date
        $reservations = $this->reservationRepository->findWithFilters(
            status: ReservationStatus::CHECKED_IN->value,
        );

        $created = 0;

        foreach ($reservations as $reservation) {
            // La recouche concerne uniquement les jours PLEINS du séjour :
            // exclure le jour d'arrivée (chambre déjà préparée) ET le jour de
            // départ (géré par une tâche DEPARTURE au check-out).
            // Comparer sur la DATE seule (pas l'heure) pour éviter les effets
            // de bord d'heure : normaliser à minuit.
            $stayStart = $reservation->getCheckIn()->setTime(0, 0, 0);
            $stayEnd   = $reservation->getCheckOut()->setTime(0, 0, 0);
            $day       = $date->setTime(0, 0, 0);

            if ($day <= $stayStart || $day >= $stayEnd) {
                continue;
            }

            $room = $reservation->getRoom();

            // Skip if an active task already exists for this room today
            if ($this->taskRepository->hasActiveTaskForRoomOnDate((string) $room->getId(), $date)) {
                continue;
            }

            $task = new CleaningTask();
            $task->setRoom($room);
            $task->setTypeEnum(CleaningType::STAY_OVER);
            $task->setStatusEnum(CleaningStatus::PENDING);
            $task->setScheduledAt($date->setTimezone($tz)->setTime(10, 0));

            $this->em->persist($task);
            $created++;
        }

        if ($created > 0) {
            $this->em->flush();
        }

        $this->logger->info('Daily housekeeping tasks generated', [
            'date'    => $date->format('Y-m-d'),
            'created' => $created,
        ]);

        return $created;
    }
}
