<?php

namespace App\Hotel\Room\Domain\Entity;

use App\Hotel\Property\Domain\Entity\Floor;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'rooms')]
#[ORM\HasLifecycleCallbacks]
class Room
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['room:read', 'reservation:read', 'reservation:gantt'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Floor::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['room:read'])]
    private ?Floor $floor = null;

    #[ORM\ManyToOne(targetEntity: RoomType::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['room:read', 'reservation:read', 'reservation:gantt'])]
    private RoomType $type;

    #[ORM\Column(length: 20, unique: true)]
    #[Groups(['room:read', 'reservation:read', 'reservation:gantt'])]
    private string $number;

    #[ORM\Column(length: 20, options: ['default' => 'available'])]
    #[Groups(['room:read', 'reservation:read'])]
    private string $status = RoomStatus::AVAILABLE->value;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['room:read'])]
    private ?string $notes = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['room:read'])]
    private bool $isActive = true;

    #[ORM\Column]
    #[Groups(['room:detail'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getFloor(): ?Floor
    {
        return $this->floor;
    }

    public function setFloor(?Floor $floor): self
    {
        $this->floor = $floor;
        return $this;
    }

    public function getType(): RoomType
    {
        return $this->type;
    }

    public function setType(RoomType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): self
    {
        $this->number = $number;
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

    public function getStatusEnum(): RoomStatus
    {
        return RoomStatus::from($this->status);
    }

    public function setStatusEnum(RoomStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
