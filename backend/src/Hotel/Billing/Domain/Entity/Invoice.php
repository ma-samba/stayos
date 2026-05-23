<?php

namespace App\Hotel\Billing\Domain\Entity;

use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
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
    #[Groups(['invoice:read', 'invoice:detail'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['invoice:detail'])]
    private Reservation $reservation;

    #[ORM\Column(length: 30, unique: true)]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private string $number;

    #[ORM\Column(length: 20, options: ['default' => 'draft'])]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private string $status = InvoiceStatus::DRAFT->value;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private string $subtotalXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '18.00'])]
    #[Groups(['invoice:detail'])]
    private string $taxRate = '18.00';

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private string $taxXof;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private string $totalXof;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:read', 'invoice:detail'])]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['invoice:detail'])]
    private ?\DateTimeImmutable $dueAt = null;

    #[ORM\Column]
    #[Groups(['invoice:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, InvoiceLine> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: InvoiceLine::class, fetch: 'EXTRA_LAZY')]
    #[Groups(['invoice:detail'])]
    private Collection $lines;

    /** @var Collection<int, Payment> */
    #[ORM\OneToMany(mappedBy: 'invoice', targetEntity: Payment::class, fetch: 'EXTRA_LAZY')]
    private Collection $payments;

    public function __construct()
    {
        $tz = new \DateTimeZone('Africa/Dakar');
        $this->createdAt = new \DateTimeImmutable('now', $tz);
        $this->updatedAt = new \DateTimeImmutable('now', $tz);
        $this->lines     = new ArrayCollection();
        $this->payments  = new ArrayCollection();
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

    // ── Lines relation ──

    /** @return Collection<int, InvoiceLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    // ── Payments relation ──

    /** @return Collection<int, Payment> */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function addPayment(Payment $payment): self
    {
        if (!$this->payments->contains($payment)) {
            $this->payments->add($payment);
            $payment->setInvoice($this);
        }
        return $this;
    }

    public function removePayment(Payment $payment): self
    {
        $this->payments->removeElement($payment);
        return $this;
    }

    /** @return Payment[] Paiements réellement encaissés (PAID) */
    #[Groups(['invoice:detail'])]
    #[SerializedName('payments')]
    public function getCompletedPayments(): array
    {
        return array_values(
            $this->payments
                ->filter(fn (Payment $p) => $p->getStatusEnum() === PaymentStatus::PAID)
                ->toArray()
        );
    }

    // ── Soldes calculés (bcmath, jamais de float) ──

    #[Groups(['invoice:read', 'invoice:detail'])]
    #[SerializedName('paidXof')]
    public function getPaidXof(): string
    {
        $total = '0';
        foreach ($this->payments as $p) {
            if ($p->getStatusEnum() === PaymentStatus::PAID) {
                $total = bcadd($total, $p->getAmountXof(), 2);
            }
        }
        return $total;
    }

    #[Groups(['invoice:read', 'invoice:detail'])]
    #[SerializedName('balanceXof')]
    public function getBalanceXof(): string
    {
        return bcsub($this->totalXof, $this->getPaidXof(), 2);
    }

    public function isFullyPaid(): bool
    {
        return bccomp($this->getBalanceXof(), '0', 2) <= 0;
    }
}
