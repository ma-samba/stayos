<?php

namespace App\Platform\Auth\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * Membre du staff d'un hôtel (schema hotel_{uuid} — pas d'annotation schema).
 * Roles : MANAGER | RECEPTIONIST | HOUSEKEEPER | ACCOUNTANT
 */
#[ORM\Entity]
#[ORM\Table(name: 'staff_users')]
#[ORM\HasLifecycleCallbacks]
class StaffUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['staff:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180, unique: true)]
    #[Groups(['staff:read'])]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 100)]
    #[Groups(['staff:read'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Groups(['staff:read'])]
    private string $lastName;

    #[ORM\Column(length: 20, options: ['default' => 'RECEPTIONIST'])]
    #[Groups(['staff:read'])]
    private string $role = 'RECEPTIONIST';

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['staff:read'])]
    private ?string $phone = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['staff:read'])]
    private bool $active = true;

    #[ORM\Column(length: 5, options: ['default' => 'fr'])]
    #[Groups(['staff:read'])]
    private string $locale = 'fr';

    #[ORM\Column(nullable: true)]
    #[Groups(['staff:read'])]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column]
    #[Groups(['staff:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->updatedAt = new \DateTimeImmutable('now', $tz);
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
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

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_' . $this->role, 'ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $dt): self
    {
        $this->lastLoginAt = $dt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[Groups(['staff:read'])]
    #[SerializedName('fullName')]
    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    /**
     * Liste de rôles exposée dans la sérialisation API (ex: ['ROLE_HOUSEKEEPER', 'ROLE_USER']).
     * Réutilise la logique de {@see getRoles()} via SerializedName pour éviter
     * d'avoir deux sources de vérité.
     */
    #[Groups(['staff:read'])]
    #[SerializedName('roles')]
    public function getRolesForApi(): array
    {
        return $this->getRoles();
    }

    public function eraseCredentials(): void {}
}
