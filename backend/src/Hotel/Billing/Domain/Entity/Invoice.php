<?php

namespace App\Hotel\Billing\Domain\Entity;

use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'invoices')]
#[ORM\HasLifecycleCallbacks]
class Invoice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Reservation $reservation;

    #[ORM\Column(length: 30, unique: true)]
    private string $number;

    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    private string $status = InvoiceStatus::DRAFT->value;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $subtotalXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '18.00'])]
    private string $taxRate = '18.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $taxXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $totalXof;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dueAt = null;

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

    public function getReservation(): Reservation
    {
        return $this->reservation;
    }

    public function setReservation(Reservation $reservation): self
    {
        $this->reservation = $reservation;
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

    public function getStatusEnum(): InvoiceStatus
    {
        return InvoiceStatus::from($this->status);
    }

    public function setStatusEnum(InvoiceStatus $status): self
    {
        $this->status = $status->value;
        return $this;
    }

    public function getSubtotalXof(): string
    {
        return $this->subtotalXof;
    }

    public function setSubtotalXof(string $subtotalXof): self
    {
        $this->subtotalXof = $subtotalXof;
        return $this;
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function setTaxRate(string $taxRate): self
    {
        $this->taxRate = $taxRate;
        return $this;
    }

    public function getTaxXof(): string
    {
        return $this->taxXof;
    }

    public function setTaxXof(string $taxXof): self
    {
        $this->taxXof = $taxXof;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(?\DateTimeImmutable $issuedAt): self
    {
        $this->issuedAt = $issuedAt;
        return $this;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function setDueAt(?\DateTimeImmutable $dueAt): self
    {
        $this->dueAt = $dueAt;
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
