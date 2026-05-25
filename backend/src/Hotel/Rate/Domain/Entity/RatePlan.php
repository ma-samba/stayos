<?php

namespace App\Hotel\Rate\Domain\Entity;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Room\Domain\Entity\RoomType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'rate_plans')]
class RatePlan
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

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['rate:read'])]
    private string $baseRateXof;

    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['rate:read'])]
    private int $minNights = 1;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['rate:read'])]
    private ?array $conditions = null;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['rate:read'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['rate:read'])]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['rate:read'])]
    private ?\DateTimeImmutable $validTo = null;

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

    public function getBaseRateXof(): string
    {
        return $this->baseRateXof;
    }

    public function setBaseRateXof(string $baseRateXof): self
    {
        $this->baseRateXof = $baseRateXof;
        return $this;
    }

    public function getMinNights(): int
    {
        return $this->minNights;
    }

    public function setMinNights(int $minNights): self
    {
        $this->minNights = $minNights;
        return $this;
    }

    public function getConditions(): ?array
    {
        return $this->conditions;
    }

    public function setConditions(?array $conditions): self
    {
        $this->conditions = $conditions;
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

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): self
    {
        $this->validFrom = $validFrom;
        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?\DateTimeImmutable $validTo): self
    {
        $this->validTo = $validTo;
        return $this;
    }
}
