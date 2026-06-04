<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Entity;

use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Facture SaaS — l'hôtel paye son abonnement à StayOS.
 *
 * Stockée dans `public.saas_invoices` pour séparer clairement la
 * facturation plateforme de la facturation client (Invoice, dans le
 * schema tenant).
 *
 * Le snapshot du plan (planName, amountXof) est conservé : si le
 * tenant change de plan plus tard, les factures historiques restent
 * exactes.
 */
#[ORM\Entity(repositoryClass: SaasInvoiceRepository::class)]
#[ORM\Table(name: 'saas_invoices', schema: 'public')]
class SaasInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    #[Groups(['saas_invoice:read'])]
    private Uuid $id;

    #[ORM\Column(length: 30, unique: true)]
    #[Groups(['saas_invoice:read'])]
    private string $number;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: Subscription::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Subscription $subscription;

    /**
     * Snapshot du plan facturé au moment de l'émission.
     */
    #[ORM\Column(length: 20)]
    #[Groups(['saas_invoice:read'])]
    private string $planName;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['saas_invoice:read'])]
    private string $amountXof;

    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[Groups(['saas_invoice:read'])]
    private string $status = SaasInvoiceStatus::DRAFT->value;

    #[ORM\Column]
    #[Groups(['saas_invoice:read'])]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column]
    #[Groups(['saas_invoice:read'])]
    private \DateTimeImmutable $periodEnd;

    #[ORM\Column(nullable: true)]
    #[Groups(['saas_invoice:read'])]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['saas_invoice:read'])]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $paydunyaToken = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Groups(['saas_invoice:read'])]
    private ?string $checkoutUrl = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['saas_invoice:read'])]
    private ?string $paymentReference = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $callbackSecret = null;

    #[ORM\Column]
    #[Groups(['saas_invoice:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getSubscription(): Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(Subscription $subscription): self
    {
        $this->subscription = $subscription;

        return $this;
    }

    public function getPlanName(): string
    {
        return $this->planName;
    }

    public function setPlanName(string $planName): self
    {
        $this->planName = $planName;

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

    public function getStatusEnum(): SaasInvoiceStatus
    {
        return SaasInvoiceStatus::from($this->status);
    }

    public function setStatus(SaasInvoiceStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): self
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(\DateTimeImmutable $periodEnd): self
    {
        $this->periodEnd = $periodEnd;

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

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeImmutable $paidAt): self
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getPaydunyaToken(): ?string
    {
        return $this->paydunyaToken;
    }

    public function setPaydunyaToken(?string $paydunyaToken): self
    {
        $this->paydunyaToken = $paydunyaToken;

        return $this;
    }

    public function getCheckoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function setCheckoutUrl(?string $checkoutUrl): self
    {
        $this->checkoutUrl = $checkoutUrl;

        return $this;
    }

    public function getPaymentReference(): ?string
    {
        return $this->paymentReference;
    }

    public function setPaymentReference(?string $paymentReference): self
    {
        $this->paymentReference = $paymentReference;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isOpen(): bool
    {
        return $this->getStatusEnum()->isOpen();
    }
}
