<?php

namespace App\Hotel\Property\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'hotel_profile')]
#[ORM\HasLifecycleCallbacks]
class HotelProfile
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 2, options: ['default' => 'SN'])]
    private string $country = 'SN';

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(nullable: true)]
    private ?int $starRating = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $totalRooms = 0;

    #[ORM\Column(length: 5, options: ['default' => '14:00'])]
    private string $checkInTime = '14:00';

    #[ORM\Column(length: 5, options: ['default' => '11:00'])]
    private string $checkOutTime = '11:00';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverPhotoUrl = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry(string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getStarRating(): ?int
    {
        return $this->starRating;
    }

    public function setStarRating(?int $starRating): self
    {
        $this->starRating = $starRating;
        return $this;
    }

    public function getTotalRooms(): int
    {
        return $this->totalRooms;
    }

    public function setTotalRooms(int $totalRooms): self
    {
        $this->totalRooms = $totalRooms;
        return $this;
    }

    public function getCheckInTime(): string
    {
        return $this->checkInTime;
    }

    public function setCheckInTime(string $checkInTime): self
    {
        $this->checkInTime = $checkInTime;
        return $this;
    }

    public function getCheckOutTime(): string
    {
        return $this->checkOutTime;
    }

    public function setCheckOutTime(string $checkOutTime): self
    {
        $this->checkOutTime = $checkOutTime;
        return $this;
    }

    public function getLogoUrl(): ?string
    {
        return $this->logoUrl;
    }

    public function setLogoUrl(?string $logoUrl): self
    {
        $this->logoUrl = $logoUrl;
        return $this;
    }

    public function getCoverPhotoUrl(): ?string
    {
        return $this->coverPhotoUrl;
    }

    public function setCoverPhotoUrl(?string $coverPhotoUrl): self
    {
        $this->coverPhotoUrl = $coverPhotoUrl;
        return $this;
    }

    public function getSettings(): ?array
    {
        return $this->settings;
    }

    public function setSettings(?array $settings): self
    {
        $this->settings = $settings;
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
}
