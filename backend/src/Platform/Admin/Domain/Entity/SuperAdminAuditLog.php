<?php

declare(strict_types=1);

namespace App\Platform\Admin\Domain\Entity;

use App\Platform\Admin\Infrastructure\Doctrine\SuperAdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Trace des actions sensibles effectuées par un SuperAdmin sur la
 * plateforme. Stocké dans `public.superadmin_audit_log` (cf.
 * migration `Version20260607100000`).
 */
#[ORM\Entity(repositoryClass: SuperAdminAuditLogRepository::class)]
#[ORM\Table(name: 'superadmin_audit_log', schema: 'public')]
class SuperAdminAuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    private string $actorEmail;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $tenantSlug = null;

    #[ORM\Column(length: 60)]
    private string $action;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $payload = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: 'text', nullable: true)]
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

    public function getActorEmail(): string
    {
        return $this->actorEmail;
    }

    public function setActorEmail(string $actorEmail): self
    {
        $this->actorEmail = $actorEmail;
        return $this;
    }

    public function getTenantSlug(): ?string
    {
        return $this->tenantSlug;
    }

    public function setTenantSlug(?string $tenantSlug): self
    {
        $this->tenantSlug = $tenantSlug;
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

    public function getPayload(): ?array
    {
        return $this->payload;
    }

    public function setPayload(?array $payload): self
    {
        $this->payload = $payload;
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
