<?php

namespace App\Platform\Subscription\Domain\Entity;

use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'subscriptions', schema: 'public')]
class Subscription
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['subscription:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['subscription:read'])]
    private Plan $plan;

    #[ORM\Column(length: 20, options: ['default' => 'trial'])]
    #[Groups(['subscription:read'])]
    private string $status = 'trial';

    #[ORM\Column(length: 10, options: ['default' => 'monthly'])]
    #[Groups(['subscription:read'])]
    private string $billingCycle = 'monthly';

    #[ORM\Column(nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $currentPeriodStart = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastNotificationSentAt = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $lastNotificationType = null;

    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getPlan(): Plan
    {
        return $this->plan;
    }

    public function setPlan(Plan $plan): self
    {
        $this->plan = $plan;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getBillingCycle(): string
    {
        return $this->billingCycle;
    }

    public function setBillingCycle(string $billingCycle): self
    {
        $this->billingCycle = $billingCycle;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): self
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getCurrentPeriodStart(): ?\DateTimeImmutable
    {
        return $this->currentPeriodStart;
    }

    public function setCurrentPeriodStart(?\DateTimeImmutable $dt): self
    {
        $this->currentPeriodStart = $dt;

        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $dt): self
    {
        $this->currentPeriodEnd = $dt;

        return $this;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeImmutable $dt): self
    {
        $this->cancelledAt = $dt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastNotificationSentAt(): ?\DateTimeImmutable
    {
        return $this->lastNotificationSentAt;
    }

    public function setLastNotificationSentAt(?\DateTimeImmutable $dt): self
    {
        $this->lastNotificationSentAt = $dt;

        return $this;
    }

    public function getLastNotificationType(): ?string
    {
        return $this->lastNotificationType;
    }

    public function setLastNotificationType(?string $type): self
    {
        $this->lastNotificationType = $type;

        return $this;
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['trial', 'active'], true);
    }

    public function isTrialExpired(): bool
    {
        return 'trial' === $this->status
            && null !== $this->trialEndsAt
            && $this->trialEndsAt < new \DateTimeImmutable();
    }
}
