<?php

namespace App\Hotel\Billing\Domain\Entity;

use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 20)]
    private string $method;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private string $amountXof;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column]
    private \DateTimeImmutable $processedAt;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->processedAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(Invoice $invoice): self
    {
        $this->invoice = $invoice;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): self
    {
        $this->method = $method;
        return $this;
    }

    public function getMethodEnum(): PaymentMethod
    {
        return PaymentMethod::from($this->method);
    }

    public function setMethodEnum(PaymentMethod $method): self
    {
        $this->method = $method->value;
        return $this;
    }

    public function getAmountXof(): string
    {
        return $this->amountXof;
    }

    public function setAmountXof(string $amountXof): self
    {
        $this->amountXof = $amountXof;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;
        return $this;
    }

    public function getProcessedAt(): \DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(\DateTimeImmutable $processedAt): self
    {
        $this->processedAt = $processedAt;
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
