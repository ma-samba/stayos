<?php

namespace App\Platform\Tenant\Domain\Entity;

use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: TenantRepository::class)]
#[ORM\Table(name: 'tenants', schema: 'public')]
#[ORM\HasLifecycleCallbacks]
class Tenant
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: 'doctrine.uuid_generator')]
    private Uuid $id;

    #[ORM\Column(length: 100, unique: true)]
    private string $slug;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(length: 20, options: ['default' => 'trial'])]
    private string $status = TenantStatus::TRIAL->value;

    #[ORM\Column(length: 100, unique: true)]
    private string $subdomain;

    #[ORM\Column(length: 50, options: ['default' => 'Africa/Dakar'])]
    private string $timezone = 'Africa/Dakar';

    #[ORM\Column(length: 2, options: ['default' => 'SN'])]
    private string $country = 'SN';

    #[ORM\Column(length: 3, options: ['default' => 'XOF'])]
    private string $currency = 'XOF';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $settings = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

        return $this;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(TenantStatus $status): self
    {
        $this->status = $status->value;

        return $this;
    }

    public function getSubdomain(): string
    {
        return $this->subdomain;
    }

    public function setSubdomain(string $subdomain): self
    {
        $this->subdomain = $subdomain;

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function setTimezone(string $timezone): self
    {
        $this->timezone = $timezone;

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

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

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

    /**
     * Heure (locale au tenant) à laquelle bascule la "business date".
     * Stockée dans settings['business_day_cutoff_hour'], défaut 5h.
     */
    public function getBusinessDayCutoffHour(): int
    {
        return (int) ($this->settings['business_day_cutoff_hour'] ?? 5);
    }

    public function setBusinessDayCutoffHour(int $hour): self
    {
        if ($hour < 0 || $hour > 23) {
            throw new \InvalidArgumentException(
                'business_day_cutoff_hour doit être un entier entre 0 et 23.'
            );
        }
        $settings = $this->settings ?? [];
        $settings['business_day_cutoff_hour'] = $hour;
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

    /**
     * Retourne le nom du schema PostgreSQL de cet hôtel.
     * Ex: hotel_550e8400_e29b_41d4_a716_446655440000
     */
    public function getSchemaName(): string
    {
        return 'hotel_' . str_replace('-', '_', (string) $this->id);
    }
}
