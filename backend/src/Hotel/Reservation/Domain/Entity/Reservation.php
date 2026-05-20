<?php

namespace App\Hotel\Reservation\Domain\Entity;

use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Reservation\Domain\Enum\ReservationSource;
use App\Hotel\Reservation\Domain\Enum\ReservationStatus;
use App\Hotel\Room\Domain\Entity\Room;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'reservations')]
#[ORM\HasLifecycleCallbacks]
class Reservation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private Uuid $id;

    #[ORM\Column(length: 30, unique: true)]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private string $confirmationNumber;

    #[ORM\ManyToOne(targetEntity: Guest::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation:read'])]
    private Guest $guest;

    #[ORM\ManyToOne(targetEntity: Room::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private Room $room;

    #[ORM\Column(length: 20, options: ['default' => 'confirmed'])]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private string $status = ReservationStatus::CONFIRMED->value;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private \DateTimeImmutable $checkIn;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['reservation:read', 'reservation:gantt'])]
    private \DateTimeImmutable $checkOut;

    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['reservation:read'])]
    private int $adults = 1;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['reservation:read'])]
    private int $children = 0;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['reservation:read'])]
    private string $rateXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['reservation:read'])]
    private string $totalXof;

    #[ORM\Column(length: 20, options: ['default' => 'direct'])]
    #[Groups(['reservation:read'])]
    private string $source = ReservationSource::DIRECT->value;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['reservation:detail'])]
    private ?string $depositXof = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['reservation:read'])]
    private ?string $notes = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['reservation:read'])]
    private ?string $specialRequests = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['reservation:read'])]
    private ?\DateTimeImmutable $checkedInAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['reservation:read'])]
    private ?\DateTimeImmutable $checkedOutAt = null;

    #[ORM\Column]
    #[Groups(['reservation:detail'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['reservation:detail'])]
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

    public function getConfirmationNumber(): string
    {
        return $this->confirmationNumber;
    }

    public function setConfirmationNumber(string $confirmationNumber): self
    {
        $this->confirmationNumber = $confirmationNumber;
        return $this;
    }

    public function getGuest(): Guest
    {
        return $this->guest;
    }

    public function setGuest(Guest $guest): self
    {
        $this->guest = $guest;
        return $this;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusEnum(): ReservationStatus
    {
        return ReservationStatus::from($this->status);
    }

    public function setStatusEnum(ReservationStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getCheckIn(): \DateTimeImmutable
    {
        return $this->checkIn;
    }

    public function setCheckIn(\DateTimeImmutable $checkIn): self
    {
        $this->checkIn = $checkIn;
        return $this;
    }

    public function getCheckOut(): \DateTimeImmutable
    {
        return $this->checkOut;
    }

    public function setCheckOut(\DateTimeImmutable $checkOut): self
    {
        $this->checkOut = $checkOut;
        return $this;
    }

    public function getAdults(): int
    {
        return $this->adults;
    }

    public function setAdults(int $adults): self
    {
        $this->adults = $adults;
        return $this;
    }

    public function getChildren(): int
    {
        return $this->children;
    }

    public function setChildren(int $children): self
    {
        $this->children = $children;
        return $this;
    }

    public function getRateXof(): string
    {
        return $this->rateXof;
    }

    public function setRateXof(string $rateXof): self
    {
        $this->rateXof = $rateXof;
        return $this;
    }

    public function getTotalXof(): string
    {
        return $this->totalXof;
    }

    public function setTotalXof(string $totalXof): self
    {
        $this->totalXof = $totalXof;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): self
    {
        $this->source = $source;
        return $this;
    }

    public function getSourceEnum(): ReservationSource
    {
        return ReservationSource::from($this->source);
    }

    public function getDepositXof(): ?string
    {
        return $this->depositXof;
    }

    public function setDepositXof(?string $depositXof): self
    {
        $this->depositXof = $depositXof;
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

    public function getSpecialRequests(): ?string
    {
        return $this->specialRequests;
    }

    public function setSpecialRequests(?string $specialRequests): self
    {
        $this->specialRequests = $specialRequests;
        return $this;
    }

    public function getCheckedInAt(): ?\DateTimeImmutable
    {
        return $this->checkedInAt;
    }

    public function setCheckedInAt(?\DateTimeImmutable $checkedInAt): self
    {
        $this->checkedInAt = $checkedInAt;
        return $this;
    }

    public function getCheckedOutAt(): ?\DateTimeImmutable
    {
        return $this->checkedOutAt;
    }

    public function setCheckedOutAt(?\DateTimeImmutable $checkedOutAt): self
    {
        $this->checkedOutAt = $checkedOutAt;
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

    // ── Méthodes calculées (PAS en BDD) ──

    public function getNights(): int
    {
        return (int) $this->checkIn->diff($this->checkOut)->days;
    }

    public function getCalculatedTotalXof(): string
    {
        $nights = $this->getNights();
        return number_format((float) $this->rateXof * $nights, 2, '.', '');
    }
}
