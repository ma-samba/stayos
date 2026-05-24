<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
use App\Hotel\Housekeeping\Domain\Service\HousekeepingService;
use App\Hotel\Housekeeping\Infrastructure\Repository\CleaningTaskRepository;
use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/housekeeping', name: 'api_housekeeping_')]
#[IsGranted('ROLE_ACCESS_HOUSEKEEPING')]
class CleaningTaskController extends AbstractApiController
{
    public function __construct(
        private readonly CleaningTaskRepository $taskRepository,
        private readonly HousekeepingService    $housekeepingService,
        private readonly StaffUserRepository    $staffUserRepository,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/housekeeping/tasks — Liste kanban.
     *
     * HOUSEKEEPER : voit uniquement ses tâches assignées.
     * MANAGER / RECEPTIONIST : voit toutes les tâches.
     */
    #[Route('/tasks', name: 'tasks', methods: ['GET'])]
    public function tasks(Request $request): JsonResponse
    {
        $staff = $this->getStaffUser();

        // Filtrage par rôle
        $assignedToId = null;
        if (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_RECEPTIONIST')) {
            $assignedToId = (string) $staff->getId();
        }

        // Date optionnelle (défaut : aujourd'hui)
        $date = null;
        $dateParam = $request->query->get('date');
        if ($dateParam !== null) {
            try {
                $date = new \DateTimeImmutable($dateParam, new \DateTimeZone('Africa/Dakar'));
            } catch (\Exception) {
                return $this->jsonError('Format de date invalide.', 'VALIDATION_ERROR', 422);
            }
        }

        $tasks = $this->taskRepository->findForBoard($assignedToId, $date);

        return $this->jsonSuccess($tasks, ['task:read']);
    }

    /**
     * PATCH /api/housekeeping/tasks/{id}/status — Changer le statut.
     *
     * HOUSEKEEPER : uniquement ses propres tâches.
     */
    #[Route('/tasks/{id}/status', name: 'update_status', methods: ['PATCH'])]
    public function updateStatus(CleaningTask $task, Request $request): JsonResponse
    {
        $staff = $this->getStaffUser();

        // HOUSEKEEPER ne peut modifier que ses propres tâches
        if (
            !$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_RECEPTIONIST')
            && (
                $task->getAssignedTo() === null
                || $task->getAssignedTo()->getId()->toRfc4122() !== $staff->getId()->toRfc4122()
            )
        ) {
            return $this->jsonError('Vous ne pouvez modifier que vos propres tâches.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $statusValue = $data['status'] ?? null;

        if ($statusValue === null) {
            return $this->jsonError('Le champ "status" est requis.', 'VALIDATION_ERROR', 422);
        }

        $newStatus = CleaningStatus::tryFrom($statusValue);
        if ($newStatus === null) {
            return $this->jsonError(
                'Statut invalide. Valeurs autorisées : ' . implode(', ', array_column(CleaningStatus::cases(), 'value')),
                'VALIDATION_ERROR',
                422,
            );
        }

        $task = $this->housekeepingService->updateStatus($task, $newStatus, $staff);

        return $this->jsonSuccess($task, ['task:read']);
    }

    /**
     * PATCH /api/housekeeping/tasks/{id}/assign — Assigner une tâche.
     *
     * Réservé aux MANAGER et RECEPTIONIST.
     */
    #[Route('/tasks/{id}/assign', name: 'assign', methods: ['PATCH'])]
    public function assign(CleaningTask $task, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_RECEPTIONIST')) {
            return $this->jsonError('Seuls les managers et réceptionnistes peuvent assigner des tâches.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $assignedToId = $data['assignedToId'] ?? null;

        $targetStaff = null;
        if ($assignedToId !== null) {
            $targetStaff = $this->staffUserRepository->find($assignedToId);
            if ($targetStaff === null) {
                return $this->jsonError('Membre du staff introuvable.', 'NOT_FOUND', 404);
            }
        }

        $task = $this->housekeepingService->assign($task, $targetStaff, $this->getStaffUser());

        return $this->jsonSuccess($task, ['task:read']);
    }
}
