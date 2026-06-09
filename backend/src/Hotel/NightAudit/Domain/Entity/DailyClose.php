<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Entity;

use App\Hotel\NightAudit\Infrastructure\Repository\DailyCloseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DailyCloseRepository::class)]
#[ORM\Table(name: 'daily_closes')]
class DailyClose
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private Uuid $id;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private \DateTimeImmutable $businessDate;

    #[ORM\Column]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private \DateTimeImmutable $closedAt;

    #[ORM\Column(type: UuidType::NAME)]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private Uuid $closedById;

    #[ORM\Column(length: 180)]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private string $closedByEmail;

    #[ORM\Column(type: 'smallint')]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private int $cutoffHour;

    /**
     * Payload figé : KPIs, comptages, caisse, factures, état des chambres.
     * Volumineux — exposé uniquement en détail.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['night_audit:detail'])]
    private array $snapshot = [];

    #[ORM\Column(nullable: true)]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private ?\DateTimeImmutable $reopenedAt = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    #[Groups(['night_audit:detail'])]
    private ?Uuid $reopenedById = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private ?string $reopenedByEmail = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['night_audit:read', 'night_audit:detail'])]
    private ?string $reopenReason = null;

    #[ORM\Column]
    #[Groups(['night_audit:detail'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBusinessDate(): \DateTimeImmutable
    {
        return $this->businessDate;
    }

    public function setBusinessDate(\DateTimeImmutable $businessDate): self
    {
        $this->businessDate = $businessDate->setTime(0, 0, 0);
        return $this;
    }

    public function getClosedAt(): \DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function setClosedAt(\DateTimeImmutable $closedAt): self
    {
        $this->closedAt = $closedAt;
        return $this;
    }

    public function getClosedById(): Uuid
    {
        return $this->closedById;
    }

    public function setClosedById(Uuid $closedById): self
    {
        $this->closedById = $closedById;
        return $this;
    }

    public function getClosedByEmail(): string
    {
        return $this->closedByEmail;
    }

    public function setClosedByEmail(string $closedByEmail): self
    {
        $this->closedByEmail = $closedByEmail;
        return $this;
    }

    public function getCutoffHour(): int
    {
        return $this->cutoffHour;
    }

    public function setCutoffHour(int $cutoffHour): self
    {
        $this->cutoffHour = $cutoffHour;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        return $this->snapshot;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function setSnapshot(array $snapshot): self
    {
        $this->snapshot = $snapshot;
        return $this;
    }

    public function getReopenedAt(): ?\DateTimeImmutable
    {
        return $this->reopenedAt;
    }

    public function setReopenedAt(?\DateTimeImmutable $reopenedAt): self
    {
        $this->reopenedAt = $reopenedAt;
        return $this;
    }

    public function getReopenedById(): ?Uuid
    {
        return $this->reopenedById;
    }

    public function setReopenedById(?Uuid $reopenedById): self
    {
        $this->reopenedById = $reopenedById;
        return $this;
    }

    public function getReopenedByEmail(): ?string
    {
        return $this->reopenedByEmail;
    }

    public function setReopenedByEmail(?string $reopenedByEmail): self
    {
        $this->reopenedByEmail = $reopenedByEmail;
        return $this;
    }

    public function getReopenReason(): ?string
    {
        return $this->reopenReason;
    }

    public function setReopenReason(?string $reopenReason): self
    {
        $this->reopenReason = $reopenReason;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isReopened(): bool
    {
        return $this->reopenedAt !== null;
    }
}
