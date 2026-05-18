<?php

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Orchestrateur des fixtures — déclare l'ordre de chargement complet.
 *
 * Ordre effectif :
 *   PlanFixtures → TenantFixtures → HotelDataFixtures → GuestFixtures
 *   → ReservationFixtures → BillingFixtures → HousekeepingFixtures
 */
class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [
            PlanFixtures::class,
            TenantFixtures::class,
            HotelDataFixtures::class,
            GuestFixtures::class,
            ReservationFixtures::class,
            BillingFixtures::class,
            HousekeepingFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // Toutes les données sont chargées par les fixtures spécialisées.
        // AppFixtures sert uniquement d'orchestrateur pour garantir l'ordre.
    }
}
