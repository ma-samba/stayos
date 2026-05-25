<?php

namespace App\DataFixtures;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Hotel\Rate\Domain\Entity\Promotion;
use App\Hotel\Rate\Domain\Entity\SeasonalRate;
use App\Hotel\Rate\Domain\Enum\PromotionType;
use App\Hotel\Rate\Domain\Enum\SeasonalRateType;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ObjectManager;

class RateFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getDependencies(): array
    {
        return [HotelDataFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        /** @var Tenant $savana */
        $savana = $this->getReference(TenantFixtures::SAVANA, Tenant::class);

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $savana->getSchemaName())
            );

            /** @var HotelProfile $hotel */
            $hotel = $this->getReference(HotelDataFixtures::HOTEL_SAVANA, HotelProfile::class);

            $this->loadSeasonalRates($manager, $hotel);
            $this->loadPromotions($manager, $hotel);

            $manager->flush();
        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }

    private function loadSeasonalRates(ObjectManager $manager, HotelProfile $hotel): void
    {
        // Haute saison — MULTIPLIER ×1.50, 2 semaines à venir, tous types
        $haute = new SeasonalRate();
        $haute->setHotel($hotel)
            ->setName('Haute saison')
            ->setTypeEnum(SeasonalRateType::MULTIPLIER)
            ->setValue('1.50')
            ->setStartDate(new \DateTimeImmutable('+7 days'))
            ->setEndDate(new \DateTimeImmutable('+21 days'))
            ->setPriority(10)
            ->setIsActive(true);
        $manager->persist($haute);

        // Tarif Magal — ABSOLUTE, période courte, priorité haute
        $magal = new SeasonalRate();
        $magal->setHotel($hotel)
            ->setName('Tarif Magal')
            ->setTypeEnum(SeasonalRateType::ABSOLUTE)
            ->setValue('75000.00')
            ->setStartDate(new \DateTimeImmutable('+30 days'))
            ->setEndDate(new \DateTimeImmutable('+34 days'))
            ->setPriority(20)
            ->setIsActive(true);
        $manager->persist($magal);
    }

    private function loadPromotions(ObjectManager $manager, HotelProfile $hotel): void
    {
        // OUVERTURE2026 — 15% de réduction, max 10 000 XOF, min 2 nuits
        $ouverture = new Promotion();
        $ouverture->setHotel($hotel)
            ->setCode('OUVERTURE2026')
            ->setDescription('Offre d\'ouverture — 15% de réduction')
            ->setTypeEnum(PromotionType::PERCENTAGE)
            ->setValue('15.00')
            ->setMaxDiscountXof('10000.00')
            ->setMinNights(2)
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setValidTo(new \DateTimeImmutable('2026-12-31'))
            ->setMaxUses(100)
            ->setIsActive(true);
        $manager->persist($ouverture);

        // LONGSEJOUR — 20 000 XOF de réduction fixe, min 5 nuits
        $long = new Promotion();
        $long->setHotel($hotel)
            ->setCode('LONGSEJOUR')
            ->setDescription('Réduction long séjour — 20 000 XOF offerts')
            ->setTypeEnum(PromotionType::FIXED)
            ->setValue('20000.00')
            ->setMinNights(5)
            ->setValidFrom(new \DateTimeImmutable('2026-01-01'))
            ->setValidTo(new \DateTimeImmutable('2026-12-31'))
            ->setIsActive(true);
        $manager->persist($long);
    }
}
