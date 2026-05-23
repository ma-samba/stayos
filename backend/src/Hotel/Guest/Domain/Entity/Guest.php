<?php

namespace App\Hotel\Guest\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'guests')]
#[ORM\HasLifecycleCallbacks]
class Guest
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['reservation:read', 'guest:read', 'invoice:detail'])]
    private Uuid $id;

    #[ORM\Column(length: 100)]
    #[Groups(['reservation:read', 'guest:read', 'invoice:detail'])]
    private string $firstName;

    #[ORM\Column(length: 100)]
    #[Groups(['reservation:read', 'guest:read', 'invoice:detail'])]
    private string $lastName;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['reservation:read', 'guest:read', 'invoice:detail'])]
    private ?string $email = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['reservation:read', 'guest:read', 'invoice:detail'])]
    private ?string $phone = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $nationality = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $documentType = null;

    #[ORM\Column(length: 60, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $documentNumber = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['guest:read', 'guest:detail'])]
    private ?string $documentUrl = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['guest:detail'])]
    private ?\DateTimeImmutable $dateOfBirth = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $city = null;

    #[ORM\Column(length: 2, nullable: true)]
    #[Groups(['guest:detail'])]
    private ?string $country = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $preferences = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['guest:read', 'guest:detail'])]
    private int $totalStays = 0;

    #[ORM\Column]
    #[Groups(['guest:detail'])]
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

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
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

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getNationality(): ?string
    {
        return $this->nationality;
    }

    public function setNationality(?string $nationality): self
    {
        $this->nationality = $nationality;
        return $this;
    }

    public function getDocumentType(): ?string
    {
        return $this->documentType;
    }

    public function setDocumentType(?string $documentType): self
    {
        $this->documentType = $documentType;
        return $this;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function setDocumentNumber(?string $documentNumber): self
    {
        $this->documentNumber = $documentNumber;
        return $this;
    }

    public function getDocumentUrl(): ?string
    {
        return $this->documentUrl;
    }

    public function setDocumentUrl(?string $documentUrl): self
    {
        $this->documentUrl = $documentUrl;
        return $this;
    }

    public function getDateOfBirth(): ?\DateTimeImmutable
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?\DateTimeImmutable $dateOfBirth): self
    {
        $this->dateOfBirth = $dateOfBirth;
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

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getPreferences(): ?array
    {
        return $this->preferences;
    }

    public function setPreferences(?array $preferences): self
    {
        $this->preferences = $preferences;
        return $this;
    }

    public function getTotalStays(): int
    {
        return $this->totalStays;
    }

    public function setTotalStays(int $totalStays): self
    {
        $this->totalStays = $totalStays;
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
