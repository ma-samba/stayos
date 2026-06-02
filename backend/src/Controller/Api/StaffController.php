<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint read-only de listing du staff du tenant courant.
 *
 * Utilisé en V1 par le sélecteur d'assignation du module Housekeeping
 * (MANAGER/RECEPTIONIST → liste des HOUSEKEEPER). Multi-tenant géré
 * automatiquement par le search_path PostgreSQL.
 */
#[Route('/api/staff', name: 'api_staff_')]
class StaffController extends AbstractApiController
{
    public function __construct(
        private readonly StaffUserRepository $repo,
    ) {}

    /**
     * GET /api/staff[?role=ROLE_HOUSEKEEPER]
     *
     * Réservé aux MANAGER et RECEPTIONIST (les deux peuvent assigner
     * des tâches ménage — cohérent avec CleaningTaskController::assign).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER') && !$this->isGranted('ROLE_RECEPTIONIST')) {
            return $this->jsonError(
                'Seuls les managers et réceptionnistes peuvent lister le staff.',
                'ACCESS_DENIED',
                403,
            );
        }

        $role = $request->query->get('role');

        $users = $role !== null && $role !== ''
            ? $this->repo->findByRole($role)
            : $this->repo->findAll();

        return $this->jsonSuccess($users, ['staff:read']);
    }
}
