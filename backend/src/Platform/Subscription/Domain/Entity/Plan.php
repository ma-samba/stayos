<?php

namespace App\Platform\Subscription\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity]
#[ORM\Table(name: 'plans', schema: 'public')]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['plan:read', 'subscription:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    #[Groups(['plan:read', 'subscription:read'])]
    private string $name;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['plan:read', 'subscription:read'])]
    private string $priceXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['plan:read', 'subscription:read'])]
    private ?string $priceEur = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['plan:read', 'subscription:read'])]
    private ?int $maxRooms = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['plan:read', 'subscription:read'])]
    private ?int $maxUsers = null;

    #[ORM\Column(type: 'json')]
    #[Groups(['plan:read', 'subscription:read'])]
    private array $features = [];

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['plan:read'])]
    private bool $isActive = true;

    public function getId(): ?int
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

    public function getPriceXof(): string
    {
        return $this->priceXof;
    }

    public function setPriceXof(string $priceXof): self
    {
        $this->priceXof = $priceXof;

        return $this;
    }

    public function getPriceEur(): ?string
    {
        return $this->priceEur;
    }

    public function setPriceEur(?string $priceEur): self
    {
        $this->priceEur = $priceEur;

        return $this;
    }

    public function getMaxRooms(): ?int
    {
        return $this->maxRooms;
    }

    public function setMaxRooms(?int $maxRooms): self
    {
        $this->maxRooms = $maxRooms;

        return $this;
    }

    public function getMaxUsers(): ?int
    {
        return $this->maxUsers;
    }

    public function setMaxUsers(?int $maxUsers): self
    {
        $this->maxUsers = $maxUsers;

        return $this;
    }

    public function getFeatures(): array
    {
        return $this->features;
    }

    public function setFeatures(array $features): self
    {
        $this->features = $features;

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

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }
}
