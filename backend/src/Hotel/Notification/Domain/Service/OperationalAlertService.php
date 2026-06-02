<?php

declare(strict_types=1);

namespace App\Hotel\Notification\Domain\Service;

use App\Hotel\Analytics\Infrastructure\Repository\AnalyticsRepository;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Shared\Mercure\MercurePublisher;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;

/**
 * Publie des alertes opérationnelles quotidiennes via Mercure :
 * arrivées du jour, départs en retard, tâches de ménage non assignées.
 */
class OperationalAlertService
{
    public function __construct(
        private readonly AnalyticsRepository    $analyticsRepository,
        private readonly CleaningTaskRepository $cleaningTaskRepository,
        private readonly MercurePublisher       $mercurePublisher,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Publie les alertes du matin : arrivées prévues et tâches non assignées.
     */
    public function publishDailyAlerts(?\DateTimeImmutable $date = null): array
    {
        $tz  = new \DateTimeZone('Africa/Dakar');
        $day = $date ?? new \DateTimeImmutable('today', $tz);

        $stats = ['arrivals' => 0, 'unassignedTasks' => 0];

        // Arrivées prévues aujourd'hui (CONFIRMED avec checkIn = today)
        $arrivals = $this->analyticsRepository->arrivalsForDay($day);

        if (\count($arrivals) > 0) {
            $arrivalData = [];
            foreach ($arrivals as $reservation) {
                $arrivalData[] = [
                    'reservationId'      => (string) $reservation->getId(),
                    'confirmationNumber' => $reservation->getConfirmationNumber(),
                    'guestName'          => $reservation->getGuest()?->getFullName(),
                    'room'               => $reservation->getRoom()?->getNumber(),
                ];
            }

            $this->mercurePublisher->publish('alert.arrivals_today', [
                'count'    => \count($arrivals),
                'arrivals' => $arrivalData,
                'date'     => $day->format('Y-m-d'),
            ]);

            $stats['arrivals'] = \count($arrivals);
        }

        // Tâches de ménage non assignées aujourd'hui
        $tasks = $this->cleaningTaskRepository->findForBoard(date: $day);
        $unassigned = array_filter($tasks, fn ($t) => $t->getAssignedTo() === null);

        if (\count($unassigned) > 0) {
            $taskData = [];
            foreach ($unassigned as $task) {
                $taskData[] = [
                    'taskId' => (string) $task->getId(),
                    'room'   => $task->getRoom()?->getNumber(),
                    'type'   => $task->getType(),
                ];
            }

            $this->mercurePublisher->publish('alert.unassigned_tasks', [
                'count' => \count($unassigned),
                'tasks' => $taskData,
                'date'  => $day->format('Y-m-d'),
            ]);

            $stats['unassignedTasks'] = \count($unassigned);
        }

        $this->logger->info('Daily operational alerts published', $stats);

        return $stats;
    }

    /**
     * Vérifie les départs en retard : réservations CHECKED_IN dont checkOut = today.
     * À appeler en fin de matinée / début d'après-midi.
     */
    public function checkLateCheckouts(?\DateTimeImmutable $date = null): int
    {
        $tz  = new \DateTimeZone('Africa/Dakar');
        $day = $date ?? new \DateTimeImmutable('today', $tz);

        $lateCheckouts = $this->analyticsRepository->departuresForDay($day);

        if (\count($lateCheckouts) === 0) {
            return 0;
        }

        $lateData = [];
        foreach ($lateCheckouts as $reservation) {
            $lateData[] = [
                'reservationId'      => (string) $reservation->getId(),
                'confirmationNumber' => $reservation->getConfirmationNumber(),
                'guestName'          => $reservation->getGuest()?->getFullName(),
                'room'               => $reservation->getRoom()?->getNumber(),
                'checkOut'           => $reservation->getCheckOut()->format('Y-m-d'),
            ];
        }

        $this->mercurePublisher->publish('alert.late_checkout', [
            'count'     => \count($lateCheckouts),
            'checkouts' => $lateData,
            'date'      => $day->format('Y-m-d'),
        ]);

        $this->logger->info('Late checkout alerts published', [
            'count' => \count($lateCheckouts),
        ]);

        return \count($lateCheckouts);
    }
}
