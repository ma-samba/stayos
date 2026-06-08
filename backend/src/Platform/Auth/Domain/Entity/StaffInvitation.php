<?php

declare(strict_types=1);

namespace App\Platform\Auth\Domain\Entity;

use App\Platform\Auth\Domain\Enum\InvitationStatus;
use App\Platform\Auth\Infrastructure\Doctrine\StaffInvitationRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Invitation à rejoindre un hôtel — table `staff_invitations` dans le
 * schema tenant.
 *
 * Le token réel est envoyé par email et n'est jamais stocké en BDD :
 * seul le SHA-256 (champ `tokenHash`) est persisté, comme pour un
 * password.
 *
 * Statut de vie :
 *   PENDING → ACCEPTED (acceptation par l'invité, création StaffUser)
 *   PENDING → EXPIRED  (expiresAt dépassé, marquage défensif à la
 *                       relecture par `StaffInvitationService::getByToken`)
 *   PENDING → REVOKED  (révocation manuelle par le manager)
 */
#[ORM\Entity(repositoryClass: StaffInvitationRepository::class)]
#[ORM\Table(name: 'staff_invitations')]
#[ORM\Index(columns: ['token_hash'], name: 'idx_staff_invitation_token')]
#[ORM\Index(columns: ['email', 'status'], name: 'idx_staff_invitation_email_status')]
class StaffInvitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['staff_invitation:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Groups(['staff_invitation:read'])]
    private string $email;

    #[ORM\Column(length: 100)]
    #[Groups(['staff_invitation:read'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Groups(['staff_invitation:read'])]
    private string $lastName;

    /**
     * MANAGER | RECEPTIONIST | ACCOUNTANT | HOUSEKEEPER
     */
    #[ORM\Column(length: 20)]
    #[Groups(['staff_invitation:read'])]
    private string $role;

    /**
     * SHA-256 hex (64 caractères) du token réel envoyé par email.
     */
    #[ORM\Column(name: 'token_hash', length: 64)]
    private string $tokenHash;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    #[Groups(['staff_invitation:read'])]
    private string $status = InvitationStatus::PENDING->value;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['staff_invitation:read'])]
    private ?Uuid $invitedBy = null;

    #[ORM\Column]
    #[Groups(['staff_invitation:read'])]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column]
    #[Groups(['staff_invitation:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['staff_invitation:read'])]
    private ?\DateTimeImmutable $acceptedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['staff_invitation:read'])]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct()
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->expiresAt = $this->createdAt->modify('+7 days');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function setRole(string $role): self
    {
        $this->role = $role;

        return $this;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function setTokenHash(string $tokenHash): self
    {
        $this->tokenHash = $tokenHash;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStatusEnum(): InvitationStatus
    {
        return InvitationStatus::from($this->status);
    }

    public function setStatus(InvitationStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getInvitedBy(): ?Uuid
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?Uuid $invitedBy): self
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->acceptedAt;
    }

    public function setAcceptedAt(?\DateTimeImmutable $acceptedAt): self
    {
        $this->acceptedAt = $acceptedAt;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): self
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING->value;
    }

    public function isExpired(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
        return $this->expiresAt < $now;
    }
}
