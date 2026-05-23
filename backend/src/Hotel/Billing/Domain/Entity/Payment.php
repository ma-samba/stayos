<?php

namespace App\Hotel\Billing\Domain\Entity;

use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'payments')]
#[ORM\Index(columns: ['gateway_token'], name: 'idx_payment_gateway_token')]
class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['payment:read', 'invoice:detail'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Invoice::class, inversedBy: 'payments')]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 20)]
    #[Groups(['payment:read', 'invoice:detail'])]
    private string $method;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['payment:read', 'invoice:detail'])]
    private string $amountXof;

    #[ORM\Column(length: 20, options: ['default' => 'init'])]
    #[Groups(['payment:read', 'invoice:detail'])]
    private string $status = PaymentStatus::INIT->value;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['payment:read', 'invoice:detail'])]
    private ?string $reference = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $gatewayToken = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Groups(['payment:read', 'invoice:detail'])]
    private ?string $gatewayName = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $rawPayload = null;

    #[ORM\Column]
    #[Groups(['payment:read', 'invoice:detail'])]
    private \DateTimeImmutable $processedAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['payment:read', 'invoice:detail'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $callbackSecret = null;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusEnum(): PaymentStatus
    {
        return PaymentStatus::from($this->status);
    }

    public function setStatusEnum(PaymentStatus $status): self
    {
        $this->status = $status->value;
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

    public function getGatewayToken(): ?string
    {
        return $this->gatewayToken;
    }

    public function setGatewayToken(?string $gatewayToken): self
    {
        $this->gatewayToken = $gatewayToken;
        return $this;
    }

    public function getGatewayName(): ?string
    {
        return $this->gatewayName;
    }

    public function setGatewayName(?string $gatewayName): self
    {
        $this->gatewayName = $gatewayName;
        return $this;
    }

    public function getRawPayload(): ?array
    {
        return $this->rawPayload;
    }

    public function setRawPayload(?array $rawPayload): self
    {
        $this->rawPayload = $rawPayload;
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

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;
        return $this;
    }

    public function getCallbackSecret(): ?string
    {
        return $this->callbackSecret;
    }

    public function setCallbackSecret(?string $callbackSecret): self
    {
        $this->callbackSecret = $callbackSecret;
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
