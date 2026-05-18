<?php

namespace App\Hotel\Shared\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'audit_logs')]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $staffUserEmail = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $staffUserRole = null;

    #[ORM\Column(length: 100)]
    private string $action;

    #[ORM\Column(length: 100)]
    private string $entityType;

    #[ORM\Column(length: 100)]
    private string $entityId;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $before = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $after = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStaffUserEmail(): ?string
    {
        return $this->staffUserEmail;
    }

    public function setStaffUserEmail(?string $email): self
    {
        $this->staffUserEmail = $email;
        return $this;
    }

    public function getStaffUserRole(): ?string
    {
        return $this->staffUserRole;
    }

    public function setStaffUserRole(?string $role): self
    {
        $this->staffUserRole = $role;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;
        return $this;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function setEntityId(string $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function getBefore(): ?array
    {
        return $this->before;
    }

    public function setBefore(?array $before): self
    {
        $this->before = $before;
        return $this;
    }

    public function getAfter(): ?array
    {
        return $this->after;
    }

    public function setAfter(?array $after): self
    {
        $this->after = $after;
        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
