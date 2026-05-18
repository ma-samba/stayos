<?php

namespace App\Hotel\Auth\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Membre du staff d'un hôtel (schema hotel_{uuid}).
 * Rôles : MANAGER | RECEPTIONIST | HOUSEKEEPER | ACCOUNTANT
 *
 * @todo Sprint 2 — Compléter avec firstName, lastName, role (StaffRole enum),
 *                  phone, active, lastLoginAt, createdAt
 */
#[ORM\Entity]
#[ORM\Table(name: 'staff_users')]
class StaffUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = ['ROLE_USER'];

    #[ORM\Column]
    private string $password;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function eraseCredentials(): void {}
}
