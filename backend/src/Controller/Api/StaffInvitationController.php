<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Platform\Auth\Domain\Enum\InvitationStatus;
use App\Platform\Auth\Domain\Service\StaffInvitationService;
use App\Platform\Auth\Infrastructure\Doctrine\StaffInvitationRepository;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints d'invitations côté manager (RBAC MANAGER).
 *
 * Le flux public d'acceptation (/public/invitations/...) est dans
 * `Controller\Public\StaffInvitationPublicController` (firewall
 * séparé sans JWT).
 */
#[Route('/api/staff/invitations', name: 'api_staff_invitations_')]
class StaffInvitationController extends AbstractApiController
{
    public function __construct(
        private readonly StaffInvitationService    $service,
        private readonly StaffInvitationRepository $repository,
    ) {}

    /**
     * GET /api/staff/invitations[?status=pending|expired|...]
     * Par défaut : pending + expired (les accepted/revoked sont du
     * bruit historique).
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $statusFilter = $request->query->get('status');

        $statuses = $statusFilter !== null && $statusFilter !== ''
            ? [$statusFilter]
            : [InvitationStatus::PENDING->value, InvitationStatus::EXPIRED->value];

        $invitations = $this->repository->findByStatuses($statuses);

        return $this->jsonSuccess($invitations, ['staff_invitation:read']);
    }

    /**
     * POST /api/staff/invitations
     * Body : { email, firstName, lastName, role }
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $body  = json_decode($request->getContent() ?: '[]', true) ?? [];
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $first = trim((string) ($body['firstName'] ?? ''));
        $last  = trim((string) ($body['lastName']  ?? ''));
        $role  = (string) ($body['role'] ?? '');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Email invalide.', 'VALIDATION_ERROR', 422);
        }
        if ($first === '' || $last === '') {
            return $this->jsonError('Prénom et nom obligatoires.', 'VALIDATION_ERROR', 422);
        }

        try {
            ['invitation' => $invitation] = $this->service->invite(
                email:     $email,
                firstName: $first,
                lastName:  $last,
                role:      $role,
                invitedBy: $this->getStaffUser(),
            );
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess($invitation, ['staff_invitation:read'], 201);
    }

    /**
     * POST /api/staff/invitations/{id}/revoke
     */
    #[Route('/{id}/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $invitation = $this->repository->find($id);
        if ($invitation === null) {
            return $this->jsonError('Invitation introuvable.', 'NOT_FOUND', 404);
        }

        try {
            $this->service->revoke($invitation, $this->getStaffUser());
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess($invitation, ['staff_invitation:read']);
    }
}
