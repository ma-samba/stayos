<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Shared\Domain\Service\AuditService;
use App\Hotel\Shared\Infrastructure\Repository\AuditLogRepository;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Domain\Service\StaffInvitationService;
use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use App\Platform\Subscription\Domain\Service\SubscriptionLimitChecker;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Security\TempPasswordGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestion du personnel hôtel — RBAC :
 *   - GET : MANAGER + RECEPTIONIST (utilisé par le sélecteur housekeeping)
 *   - POST / PUT / DELETE / reset-password / reactivate : MANAGER uniquement
 *
 * Multi-tenant géré automatiquement par le search_path PostgreSQL
 * (`TenantMiddleware` ou `JWTDecodedListener`).
 *
 * Soft delete : `DELETE` ne supprime pas physiquement, passe
 * `active = false` (préserve audit log + permet la réactivation).
 */
#[Route('/api/staff', name: 'api_staff_')]
class StaffController extends AbstractApiController
{
    public function __construct(
        private readonly StaffUserRepository         $repo,
        private readonly EntityManagerInterface      $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly SubscriptionLimitChecker    $limitChecker,
        private readonly AuditService                $auditService,
        private readonly AuditLogRepository          $auditLogRepository,
        private readonly TempPasswordGenerator       $tempPasswordGenerator,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/staff[?role=ROLE_HOUSEKEEPER]
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

    /**
     * POST /api/staff — création directe par le manager (le password
     * temporaire est retourné UNE FOIS, à transmettre en main propre).
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
        $phone = isset($body['phone']) ? trim((string) $body['phone']) : null;

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('Email invalide.', 'VALIDATION_ERROR', 422);
        }
        if ($first === '' || $last === '') {
            return $this->jsonError('Prénom et nom obligatoires.', 'VALIDATION_ERROR', 422);
        }
        if (!in_array($role, StaffInvitationService::ALLOWED_ROLES, true)) {
            return $this->jsonError('Rôle invalide.', 'VALIDATION_ERROR', 422);
        }

        if ($this->repo->findByEmail($email) !== null) {
            return $this->jsonError(
                'Un employé existe déjà avec cet email.',
                'ALREADY_EXISTS',
                409,
            );
        }

        try {
            $this->limitChecker->assertCanAddUser();
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        $tempPassword = $this->tempPasswordGenerator->generate();

        $staff = new StaffUser();
        $staff->setEmail($email);
        $staff->setFirstName($first);
        $staff->setLastName($last);
        $staff->setRole($role);
        $staff->setPhone($phone !== '' ? $phone : null);
        $staff->setActive(true);
        $staff->setPassword($this->passwordHasher->hashPassword($staff, $tempPassword));

        $this->em->persist($staff);
        $this->em->flush();

        $this->auditService->log(
            action:     'staff_user.created',
            entityType: 'StaffUser',
            entityId:   (string) $staff->getId(),
            before:     null,
            after:      [
                'email'     => $staff->getEmail(),
                'firstName' => $staff->getFirstName(),
                'lastName'  => $staff->getLastName(),
                'role'      => $staff->getRole(),
                'phone'     => $staff->getPhone(),
            ],
            staffUser:  $this->getStaffUser(),
        );
        $this->em->flush();

        $this->logger->info('staff_user.created_by_manager', [
            'email'      => $email,
            'role'       => $role,
            'created_by' => (string) $this->getStaffUser()->getId(),
        ]);

        return $this->json([
            'data' => [
                'id'            => (string) $staff->getId(),
                'email'         => $staff->getEmail(),
                'firstName'     => $staff->getFirstName(),
                'lastName'      => $staff->getLastName(),
                'role'          => $staff->getRole(),
                'phone'         => $staff->getPhone(),
                'active'        => $staff->isActive(),
                'tempPassword'  => $tempPassword,
            ],
            'message' => 'Employé créé. Le mot de passe temporaire ne sera plus affiché — communiquez-le immédiatement.',
            'status'  => 201,
        ], 201);
    }

    /**
     * PUT /api/staff/{id} — édition de firstName/lastName/role/phone.
     * Email et password NE PEUVENT PAS être modifiés ici.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        $body = json_decode($request->getContent() ?: '[]', true) ?? [];

        // ⚠️ Snapshot AVANT mutation pour l'audit log
        $before = [
            'firstName' => $staff->getFirstName(),
            'lastName'  => $staff->getLastName(),
            'role'      => $staff->getRole(),
            'phone'     => $staff->getPhone(),
        ];

        if (isset($body['firstName'])) {
            $value = trim((string) $body['firstName']);
            if ($value === '') {
                return $this->jsonError('Prénom invalide.', 'VALIDATION_ERROR', 422);
            }
            $staff->setFirstName($value);
        }

        if (isset($body['lastName'])) {
            $value = trim((string) $body['lastName']);
            if ($value === '') {
                return $this->jsonError('Nom invalide.', 'VALIDATION_ERROR', 422);
            }
            $staff->setLastName($value);
        }

        if (isset($body['role'])) {
            if (!in_array($body['role'], StaffInvitationService::ALLOWED_ROLES, true)) {
                return $this->jsonError('Rôle invalide.', 'VALIDATION_ERROR', 422);
            }
            $staff->setRole((string) $body['role']);
        }

        if (array_key_exists('phone', $body)) {
            $phone = $body['phone'] !== null ? trim((string) $body['phone']) : null;
            $staff->setPhone($phone !== '' ? $phone : null);
        }

        $after = [
            'firstName' => $staff->getFirstName(),
            'lastName'  => $staff->getLastName(),
            'role'      => $staff->getRole(),
            'phone'     => $staff->getPhone(),
        ];

        // Skip audit si aucun champ pertinent n'a changé (évite le bruit)
        if ($before !== $after) {
            $this->auditService->log(
                action:     'staff_user.updated',
                entityType: 'StaffUser',
                entityId:   (string) $staff->getId(),
                before:     $before,
                after:      $after,
                staffUser:  $this->getStaffUser(),
            );
        }

        $this->em->flush();

        return $this->jsonSuccess($staff, ['staff:read']);
    }

    /**
     * POST /api/staff/{id}/reset-password — génère un nouveau password
     * temporaire, retourné UNE FOIS au manager.
     */
    #[Route('/{id}/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        $tempPassword = $this->tempPasswordGenerator->generate();
        $staff->setPassword($this->passwordHasher->hashPassword($staff, $tempPassword));

        // ⚠️ Action sensible : trace l'occurrence sans rien fuiter
        // (ni l'ancien hash ni le nouveau password).
        $this->auditService->log(
            action:     'staff_user.password_reset',
            entityType: 'StaffUser',
            entityId:   (string) $staff->getId(),
            before:     null,
            after:      null,
            staffUser:  $this->getStaffUser(),
        );

        $this->em->flush();

        $this->logger->warning('staff_user.password_reset_by_manager', [
            'staff_id'  => (string) $staff->getId(),
            'reset_by'  => (string) $this->getStaffUser()->getId(),
        ]);

        return $this->json([
            'data'    => ['tempPassword' => $tempPassword],
            'message' => 'Mot de passe réinitialisé. Communiquez-le immédiatement, il ne sera plus affiché.',
            'status'  => 200,
        ]);
    }

    /**
     * DELETE /api/staff/{id} — soft delete (active = false).
     */
    #[Route('/{id}', name: 'deactivate', methods: ['DELETE'])]
    public function deactivate(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        if ((string) $staff->getId() === (string) $this->getStaffUser()->getId()) {
            return $this->jsonError(
                'Vous ne pouvez pas vous désactiver vous-même.',
                'BUSINESS_RULE',
                422,
            );
        }

        $staff->setActive(false);

        $this->auditService->log(
            action:     'staff_user.deactivated',
            entityType: 'StaffUser',
            entityId:   (string) $staff->getId(),
            before:     ['active' => true],
            after:      ['active' => false],
            staffUser:  $this->getStaffUser(),
        );

        $this->em->flush();

        $this->logger->info('staff_user.deactivated', [
            'staff_id'        => (string) $staff->getId(),
            'deactivated_by'  => (string) $this->getStaffUser()->getId(),
        ]);

        return $this->jsonSuccess($staff, ['staff:read']);
    }

    /**
     * POST /api/staff/{id}/reactivate — repasse active=true.
     * Re-check la limite plan : si on est déjà au maximum, refus 422.
     */
    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        if ($staff->isActive()) {
            return $this->jsonError(
                'Cet employé est déjà actif.',
                'BUSINESS_RULE',
                422,
            );
        }

        try {
            $this->limitChecker->assertCanAddUser();
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        $staff->setActive(true);

        $this->auditService->log(
            action:     'staff_user.reactivated',
            entityType: 'StaffUser',
            entityId:   (string) $staff->getId(),
            before:     ['active' => false],
            after:      ['active' => true],
            staffUser:  $this->getStaffUser(),
        );

        $this->em->flush();

        $this->logger->info('staff_user.reactivated', [
            'staff_id'      => (string) $staff->getId(),
            'reactivated_by' => (string) $this->getStaffUser()->getId(),
        ]);

        return $this->jsonSuccess($staff, ['staff:read']);
    }

    /**
     * GET /api/staff/{id}/audit — historique des actions sur cet employé.
     * Lecture seule, 50 dernières entrées triées DESC.
     */
    #[Route('/{id}/audit', name: 'audit', methods: ['GET'])]
    public function audit(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        $logs = $this->auditLogRepository->findByEntity(
            entityType: 'StaffUser',
            entityId:   (string) $staff->getId(),
            limit:      50,
        );

        return $this->json([
            'data' => array_map(fn ($log) => [
                'id'             => (string) $log->getId(),
                'action'         => $log->getAction(),
                'staffUserEmail' => $log->getStaffUserEmail(),
                'staffUserRole'  => $log->getStaffUserRole(),
                'before'         => $log->getBefore(),
                'after'          => $log->getAfter(),
                'createdAt'      => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $logs),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    /**
     * GET /api/staff/{id}/activity — actions FAITES par cet employé
     * (toutes entités confondues : reservation, payment, cleaning…).
     * 100 dernières entrées, triées DESC. MANAGER only.
     */
    #[Route('/{id}/activity', name: 'activity', methods: ['GET'])]
    public function activity(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $staff = $this->repo->find($id);
        if ($staff === null) {
            return $this->jsonError('Employé introuvable.', 'NOT_FOUND', 404);
        }

        $logs = $this->auditLogRepository->findByStaffUser(
            email: $staff->getEmail(),
            limit: 100,
        );

        return $this->json([
            'data' => array_map(fn ($log) => [
                'id'         => (string) $log->getId(),
                'action'     => $log->getAction(),
                'entityType' => $log->getEntityType(),
                'entityId'   => $log->getEntityId(),
                'before'     => $log->getBefore(),
                'after'      => $log->getAfter(),
                'createdAt'  => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $logs),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

}
