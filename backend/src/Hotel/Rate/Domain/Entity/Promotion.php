<?php

namespace App\Hotel\Rate\Domain\Entity;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\Enum\PromotionType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'promotions')]
class Promotion
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['promotion:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: HotelProfile::class)]
    #[ORM\JoinColumn(nullable: false)]
    private HotelProfile $hotel;

    #[ORM\Column(length: 50)]
    #[Groups(['promotion:read'])]
    private string $code;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['promotion:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    #[Groups(['promotion:read'])]
    private string $type;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['promotion:read'])]
    private string $value;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promotion:read'])]
    private ?string $maxDiscountXof = null;

    #[ORM\Column(options: ['default' => 1])]
    #[Groups(['promotion:read'])]
    private int $minNights = 1;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['promotion:read'])]
    private ?string $minAmountXof = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['promotion:read'])]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    #[Groups(['promotion:read'])]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['promotion:read'])]
    private ?int $maxUses = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $usedCount = 0;

    #[ORM\Column(options: ['default' => true])]
    #[Groups(['promotion:read'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $applicableRoomTypeIds = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $applicableRatePlanIds = null;

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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getTypeEnum(): PromotionType
    {
        return PromotionType::from($this->type);
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function setTypeEnum(PromotionType $type): self
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

    public function getMaxDiscountXof(): ?string
    {
        return $this->maxDiscountXof;
    }

    public function setMaxDiscountXof(?string $maxDiscountXof): self
    {
        $this->maxDiscountXof = $maxDiscountXof;
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

    public function getMinAmountXof(): ?string
    {
        return $this->minAmountXof;
    }

    public function setMinAmountXof(?string $minAmountXof): self
    {
        $this->minAmountXof = $minAmountXof;
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

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;
        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function setUsedCount(int $usedCount): self
    {
        $this->usedCount = $usedCount;
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

    public function getApplicableRoomTypeIds(): ?array
    {
        return $this->applicableRoomTypeIds;
    }

    public function setApplicableRoomTypeIds(?array $applicableRoomTypeIds): self
    {
        $this->applicableRoomTypeIds = $applicableRoomTypeIds;
        return $this;
    }

    public function getApplicableRatePlanIds(): ?array
    {
        return $this->applicableRatePlanIds;
    }

    public function setApplicableRatePlanIds(?array $applicableRatePlanIds): self
    {
        $this->applicableRatePlanIds = $applicableRatePlanIds;
        return $this;
    }

    // ── Méthodes utilitaires (logique métier) ──

    public function isValidOn(\DateTimeImmutable $date): bool
    {
        if (!$this->isActive) {
            return false;
        }

        $day = $date->format('Y-m-d');

        if ($this->validFrom !== null && $day < $this->validFrom->format('Y-m-d')) {
            return false;
        }

        if ($this->validTo !== null && $day > $this->validTo->format('Y-m-d')) {
            return false;
        }

        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return false;
        }

        return true;
    }

    public function appliesToRoomType(?Uuid $roomTypeId): bool
    {
        if (empty($this->applicableRoomTypeIds)) {
            return true;
        }

        if ($roomTypeId === null) {
            return false;
        }

        return in_array((string) $roomTypeId, $this->applicableRoomTypeIds, true);
    }

    public function appliesToRatePlan(?Uuid $ratePlanId): bool
    {
        if (empty($this->applicableRatePlanIds)) {
            return true;
        }

        if ($ratePlanId === null) {
            return false;
        }

        return in_array((string) $ratePlanId, $this->applicableRatePlanIds, true);
    }
}
