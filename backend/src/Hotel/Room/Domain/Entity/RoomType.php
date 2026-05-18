<?php

namespace App\Hotel\Room\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'room_types')]
class RoomType
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $baseRateXof;

    #[ORM\Column(options: ['default' => 2])]
    private int $maxOccupancy = 2;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bedConfiguration = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $amenities = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
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

    public function getMaxOccupancy(): int
    {
        return $this->maxOccupancy;
    }

    public function setMaxOccupancy(int $maxOccupancy): self
    {
        $this->maxOccupancy = $maxOccupancy;
        return $this;
    }

    public function getBedConfiguration(): ?array
    {
        return $this->bedConfiguration;
    }

    public function setBedConfiguration(?array $bedConfiguration): self
    {
        $this->bedConfiguration = $bedConfiguration;
        return $this;
    }

    public function getAmenities(): ?array
    {
        return $this->amenities;
    }

    public function setAmenities(?array $amenities): self
    {
        $this->amenities = $amenities;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
