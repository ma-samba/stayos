<?php

namespace App\Hotel\Housekeeping\Domain\Entity;

use App\Hotel\Housekeeping\Domain\Enum\CleaningStatus;
use App\Hotel\Housekeeping\Domain\Enum\CleaningType;
use App\Hotel\Room\Domain\Entity\Room;
use App\Platform\Auth\Domain\Entity\StaffUser;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'cleaning_tasks')]
class CleaningTask
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Room $room;

    #[ORM\ManyToOne(targetEntity: StaffUser::class)]
    #[ORM\JoinColumn(name: 'assigned_to', nullable: true)]
    private ?StaffUser $assignedTo = null;

    #[ORM\Column(length: 20, options: ['default' => 'pending'])]
    private string $status = CleaningStatus::PENDING->value;

    #[ORM\Column(length: 20, options: ['default' => 'stay_over'])]
    private string $type = CleaningType::STAY_OVER->value;

    #[ORM\Column]
    private \DateTimeImmutable $scheduledAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->scheduledAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRoom(): Room
    {
        return $this->room;
    }

    public function setRoom(Room $room): self
    {
        $this->room = $room;
        return $this;
    }

    public function getAssignedTo(): ?StaffUser
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?StaffUser $assignedTo): self
    {
        $this->assignedTo = $assignedTo;
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

    public function getStatusEnum(): CleaningStatus
    {
        return CleaningStatus::from($this->status);
    }

    public function setStatusEnum(CleaningStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getTypeEnum(): CleaningType
    {
        return CleaningType::from($this->type);
    }

    public function setTypeEnum(CleaningType $type): self
    {
        $this->type = $type->value;
        return $this;
    }

    public function getScheduledAt(): \DateTimeImmutable
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(\DateTimeImmutable $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;
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
}
