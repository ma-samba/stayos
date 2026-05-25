<?php

namespace App\Hotel\Rate\Domain\Entity;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\Enum\SeasonalRateType;
use App\Hotel\Room\Domain\Entity\RoomType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'seasonal_rates')]
class SeasonalRate
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['rate:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: HotelProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private HotelProfile $hotel;

    #[ORM\ManyToOne(targetEntity: RoomType::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?RoomType $roomType = null;

    #[ORM\Column(length: 150)]
    #[Groups(['rate:read'])]
    private string $name;

    #[ORM\Column(length: 20)]
    #[Groups(['rate:read'])]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['rate:read'])]
    private string $value;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['rate:read'])]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(type: 'date_immutable')]
    #[Groups(['rate:read'])]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['rate:read'])]
    private int $priority = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['rate:read'])]
    private bool $isActive = true;

    // ── Getters / Setters ──

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getHotel(): HotelProfile
    {
        return $this->hotel;
    }

    public function setHotel(HotelProfile $hotel): self
    {
        $this->hotel = $hotel;
        return $this;
    }

    public function getRoomType(): ?RoomType
    {
        return $this->roomType;
    }

    public function setRoomType(?RoomType $roomType): self
    {
        $this->roomType = $roomType;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTypeEnum(): SeasonalRateType
    {
        return SeasonalRateType::from($this->type);
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setTypeEnum(SeasonalRateType $type): self
    {
        $this->type = $type->value;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): self
    {
        $this->startDate = $startDate;
        return $this;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): self
    {
        $this->endDate = $endDate;
        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
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

    // ── Méthodes utilitaires ──

    public function coversDate(\DateTimeImmutable $date): bool
    {
        $day   = $date->format('Y-m-d');
        $start = $this->startDate->format('Y-m-d');
        $end   = $this->endDate->format('Y-m-d');

        return $day >= $start && $day <= $end;
    }
}
