<?php

declare(strict_types=1);

namespace App\Platform\Auth\Domain\Service;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Domain\Enum\InvitationStatus;
use App\Platform\Auth\Infrastructure\Doctrine\StaffInvitationRepository;
use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use App\Platform\Subscription\Domain\Service\SubscriptionLimitChecker;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\Email\EmailService;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cycle de vie des invitations d'employés (Sprint 13bis).
 *
 * Toutes les méthodes opèrent dans le schema tenant courant —
 * `TenantMiddleware` ou `JWTDecodedListener` ont déjà posé le
 * search_path.
 *
 * Le token réel est généré ici (`random_bytes(32)` → 64 hex chars)
 * et n'est jamais stocké en BDD : seul son SHA-256 est persisté.
 * Le token en clair est retourné une seule fois pour l'email.
 *
 * Rôles autorisés : MANAGER, RECEPTIONIST, ACCOUNTANT, HOUSEKEEPER.
 */
class StaffInvitationService
{
    public const ALLOWED_ROLES = ['MANAGER', 'RECEPTIONIST', 'ACCOUNTANT', 'HOUSEKEEPER'];

    public const TOKEN_LIFETIME_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface       $entityManager,
        private readonly StaffInvitationRepository    $invitationRepository,
        private readonly StaffUserRepository          $staffUserRepository,
        private readonly SubscriptionLimitChecker     $limitChecker,
        private readonly EmailService                 $emailService,
        private readonly UserPasswordHasherInterface  $passwordHasher,
        private readonly TenantContext                $tenantContext,
        private readonly AuditService                 $auditService,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Émet une invitation pour un nouvel employé. Retourne l'invitation
     * persistée + le token en clair (à transmettre uniquement par email).
     *
     * @return array{invitation: StaffInvitation, token: string}
     */
    public function invite(
        string    $email,
        string    $firstName,
        string    $lastName,
        string    $role,
        StaffUser $invitedBy,
    ): array {
        $email = strtolower(trim($email));

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new BusinessRuleException(sprintf(
                "Rôle invalide '%s'. Valeurs autorisées : %s.",
                $role,
                implode(', ', self::ALLOWED_ROLES),
            ));
        }

        $existing = $this->staffUserRepository->findByEmail($email);
        if ($existing !== null && $existing->isActive()) {
            throw new AlreadyExistsException(sprintf(
                "Un employé actif existe déjà avec l'email %s.",
                $email,
            ));
        }

        $pending = $this->invitationRepository->findPendingByEmail($email);
        if ($pending !== null) {
            throw new AlreadyExistsException(sprintf(
                "Une invitation est déjà en attente pour %s.",
                $email,
            ));
        }

        // ⚠️ Limite plan : peut lever BusinessRuleException (422)
        $this->limitChecker->assertCanAddUser();

        $plainToken = bin2hex(random_bytes(32));
        $tokenHash  = hash('sha256', $plainToken);

        $invitation = new StaffInvitation();
        $invitation->setEmail($email);
        $invitation->setFirstName($firstName);
        $invitation->setLastName($lastName);
        $invitation->setRole($role);
        $invitation->setTokenHash($tokenHash);
        $invitation->setInvitedBy($invitedBy->getId());

        $this->entityManager->persist($invitation);

        $this->auditService->log(
            action:     'staff_invitation.created',
            entityType: 'StaffInvitation',
            entityId:   (string) $invitation->getId(),
            before:     null,
            after:      [
                'email'     => $invitation->getEmail(),
                'role'      => $invitation->getRole(),
                'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ],
            staffUser:  $invitedBy,
        );

        $this->entityManager->flush();

        // ── Envoi de l'email ─────────────────────────────────────
        $tenant    = $this->tenantContext->get();
        $hotelName = $this->resolveHotelName($tenant);

        $this->emailService->sendStaffInvitation(
            invitation:  $invitation,
            plainToken:  $plainToken,
            tenantSlug:  $tenant->getSlug(),
            hotelName:   $hotelName,
        );

        $this->logger->info('staff_invitation.created', [
            'tenant'     => $tenant->getSlug(),
            'email'      => $email,
            'role'       => $role,
            'invited_by' => (string) $invitedBy->getId(),
        ]);

        return ['invitation' => $invitation, 'token' => $plainToken];
    }

    /**
     * Récupère une invitation à partir d'un token en clair. Marque
     * automatiquement EXPIRED si elle est en PENDING mais que la date
     * d'expiration est passée.
     *
     * Lève BusinessRuleException si la session n'est plus utilisable
     * (expirée, déjà acceptée, révoquée).
     */
    public function getByToken(string $token): StaffInvitation
    {
        $tokenHash  = hash('sha256', $token);
        $invitation = $this->invitationRepository->findByTokenHash($tokenHash);

        if ($invitation === null) {
            throw new BusinessRuleException('Invitation introuvable ou déjà utilisée.');
        }

        if ($invitation->getStatus() !== InvitationStatus::PENDING->value) {
            throw new BusinessRuleException('Invitation expirée ou déjà utilisée.');
        }

        if ($invitation->isExpired()) {
            // Marquage défensif pour éviter de retomber dessus
            $invitation->setStatus(InvitationStatus::EXPIRED);
            $this->entityManager->flush();
            throw new BusinessRuleException('Invitation expirée ou déjà utilisée.');
        }

        return $invitation;
    }

    /**
     * Accepte une invitation : crée un StaffUser dans le schema tenant
     * courant, marque l'invitation ACCEPTED.
     */
    public function accept(string $token, string $plainPassword): StaffUser
    {
        $invitation = $this->getByToken($token);

        // Garde de concurrence : l'admin a pu créer manuellement
        // entre-temps un compte avec ce même email.
        if ($this->staffUserRepository->findByEmail($invitation->getEmail()) !== null) {
            throw new AlreadyExistsException(
                'Un compte existe déjà avec cet email. Demandez un reset de mot de passe au manager.',
            );
        }

        $staffUser = new StaffUser();
        $staffUser->setEmail($invitation->getEmail());
        $staffUser->setFirstName($invitation->getFirstName());
        $staffUser->setLastName($invitation->getLastName());
        $staffUser->setRole($invitation->getRole());
        $staffUser->setActive(true);
        $staffUser->setPassword($this->passwordHasher->hashPassword($staffUser, $plainPassword));

        $this->entityManager->persist($staffUser);

        $invitation->setStatus(InvitationStatus::ACCEPTED);
        $invitation->setAcceptedAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));

        $this->entityManager->flush();

        // ⚠️ L'invité n'est pas (encore) loggué : staffUser = null.
        // L'identité de la personne qui a accepté est dans `after.email`.
        $this->auditService->log(
            action:     'staff_user.created_via_invitation',
            entityType: 'StaffUser',
            entityId:   (string) $staffUser->getId(),
            before:     null,
            after:      [
                'email' => $staffUser->getEmail(),
                'role'  => $staffUser->getRole(),
            ],
            staffUser:  null,
        );
        $this->entityManager->flush();

        $this->logger->info('staff_invitation.accepted', [
            'tenant' => $this->tenantContext->get()->getSlug(),
            'email'  => $invitation->getEmail(),
            'role'   => $invitation->getRole(),
        ]);

        return $staffUser;
    }

    /**
     * Révoque manuellement une invitation PENDING.
     *
     * `$revokedBy` est l'acteur (le manager) qui révoque ; il est
     * propagé à l'audit log.
     */
    public function revoke(StaffInvitation $invitation, ?StaffUser $revokedBy = null): void
    {
        if (!$invitation->isPending()) {
            throw new BusinessRuleException(
                'Seules les invitations en attente peuvent être révoquées.',
            );
        }

        $invitation->setStatus(InvitationStatus::REVOKED);
        $invitation->setRevokedAt(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar')));

        $this->auditService->log(
            action:     'staff_invitation.revoked',
            entityType: 'StaffInvitation',
            entityId:   (string) $invitation->getId(),
            before:     ['status' => InvitationStatus::PENDING->value],
            after:      ['status' => InvitationStatus::REVOKED->value],
            staffUser:  $revokedBy,
        );

        $this->entityManager->flush();

        $this->logger->info('staff_invitation.revoked', [
            'tenant' => $this->tenantContext->get()->getSlug(),
            'email'  => $invitation->getEmail(),
        ]);
    }

    private function resolveHotelName(Tenant $tenant): string
    {
        $hotel = $this->entityManager->getRepository(HotelProfile::class)->findOneBy([]);
        return $hotel?->getName() ?? $tenant->getName();
    }
}
