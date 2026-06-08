<?php

declare(strict_types=1);

namespace App\Platform\Admin\Domain\Service;

use App\Hotel\Property\Domain\Entity\Floor;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Entity\RoomType;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sprint 13ter — Pré-remplit un tenant fraîchement provisionné avec
 * un template d'amorce (vente directe, migration depuis un autre
 * PMS, démo commerciale).
 *
 * IMPORTANT : la méthode `seed()` doit être appelée DEPUIS l'intérieur
 * du search_path du tenant. Le caller (OnboardingService::provision)
 * pose `SET search_path TO hotel_{uuid}, public` avant d'appeler.
 *
 * Les données créées sont 100 % modifiables ensuite par le manager —
 * rien n'est figé. Cf. backlog : variante 3 (écran SuperAdmin
 * dédié à la configuration) reportée V2.
 */
final class TenantSeedService
{
    public const TEMPLATE_EMPTY        = 'empty';
    public const TEMPLATE_SMALL_HOTEL  = 'small_hotel';
    public const TEMPLATE_MEDIUM_HOTEL = 'medium_hotel';

    public const ALLOWED_TEMPLATES = [
        self::TEMPLATE_EMPTY,
        self::TEMPLATE_SMALL_HOTEL,
        self::TEMPLATE_MEDIUM_HOTEL,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function seed(Tenant $tenant, string $template): void
    {
        match ($template) {
            self::TEMPLATE_EMPTY        => null,
            self::TEMPLATE_SMALL_HOTEL  => $this->seedSmallHotel(),
            self::TEMPLATE_MEDIUM_HOTEL => $this->seedMediumHotel(),
            default => throw new BusinessRuleException(
                sprintf("Template '%s' inconnu.", $template),
            ),
        };
    }

    /**
     * 1 étage, 1 type "Standard", 5 chambres numérotées 101-105.
     */
    private function seedSmallHotel(): void
    {
        $floor = new Floor();
        $floor->setNumber(1);
        $floor->setName('Rez-de-chaussée');
        $this->entityManager->persist($floor);

        $type = new RoomType();
        $type->setName('Standard');
        $type->setDescription('Chambre standard double');
        $type->setBaseRateXof('25000.00');
        $type->setMaxOccupancy(2);
        $type->setSortOrder(0);
        $this->entityManager->persist($type);

        for ($i = 1; $i <= 5; $i++) {
            $room = new Room();
            $room->setFloor($floor);
            $room->setType($type);
            $room->setNumber('10' . $i);
            $room->setStatusEnum(RoomStatus::AVAILABLE);
            $this->entityManager->persist($room);
        }

        $this->entityManager->flush();
    }

    /**
     * 2 étages, 2 types (Standard 25k / Deluxe 45k), 12 chambres
     * (6 Standard au RDC numérotées 101-106, 6 Deluxe au 1er
     * numérotées 201-206).
     */
    private function seedMediumHotel(): void
    {
        $rdc = new Floor();
        $rdc->setNumber(1);
        $rdc->setName('Rez-de-chaussée');
        $this->entityManager->persist($rdc);

        $etage1 = new Floor();
        $etage1->setNumber(2);
        $etage1->setName('Premier étage');
        $this->entityManager->persist($etage1);

        $standard = new RoomType();
        $standard->setName('Standard');
        $standard->setDescription('Chambre standard double');
        $standard->setBaseRateXof('25000.00');
        $standard->setMaxOccupancy(2);
        $standard->setSortOrder(0);
        $this->entityManager->persist($standard);

        $deluxe = new RoomType();
        $deluxe->setName('Deluxe');
        $deluxe->setDescription('Chambre deluxe avec vue');
        $deluxe->setBaseRateXof('45000.00');
        $deluxe->setMaxOccupancy(2);
        $deluxe->setSortOrder(1);
        $this->entityManager->persist($deluxe);

        for ($i = 1; $i <= 6; $i++) {
            $room = new Room();
            $room->setFloor($rdc);
            $room->setType($standard);
            $room->setNumber('10' . $i);
            $room->setStatusEnum(RoomStatus::AVAILABLE);
            $this->entityManager->persist($room);
        }

        for ($i = 1; $i <= 6; $i++) {
            $room = new Room();
            $room->setFloor($etage1);
            $room->setType($deluxe);
            $room->setNumber('20' . $i);
            $room->setStatusEnum(RoomStatus::AVAILABLE);
            $this->entityManager->persist($room);
        }

        $this->entityManager->flush();
    }
}
