<?php

namespace App\DataFixtures;

use App\Platform\Subscription\Domain\Entity\Plan;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PlanFixtures extends Fixture
{
    public const STARTER    = 'plan-starter';
    public const PRO        = 'plan-pro';
    public const ENTERPRISE = 'plan-enterprise';

    public function load(ObjectManager $manager): void
    {
        $plans = [
            [
                'ref'       => self::STARTER,
                'name'      => 'STARTER',
                'priceXof'  => '15000.00',
                'priceEur'  => '23.00',
                'maxRooms'  => 20,
                'maxUsers'  => 5,
                'features'  => [],
            ],
            [
                'ref'       => self::PRO,
                'name'      => 'PRO',
                'priceXof'  => '35000.00',
                'priceEur'  => '53.00',
                'maxRooms'  => 100,
                'maxUsers'  => 20,
                'features'  => ['channel_manager', 'advanced_reports', 'revenue_management'],
            ],
            [
                'ref'       => self::ENTERPRISE,
                'name'      => 'ENTERPRISE',
                'priceXof'  => '75000.00',
                'priceEur'  => '115.00',
                'maxRooms'  => null,
                'maxUsers'  => null,
                'features'  => ['channel_manager', 'advanced_reports', 'api_access', 'multi_property', 'revenue_management'],
            ],
        ];

        foreach ($plans as $data) {
            $plan = new Plan();
            $plan->setName($data['name']);
            $plan->setPriceXof($data['priceXof']);
            $plan->setPriceEur($data['priceEur']);
            $plan->setMaxRooms($data['maxRooms']);
            $plan->setMaxUsers($data['maxUsers']);
            $plan->setFeatures($data['features']);

            $manager->persist($plan);
            $this->addReference($data['ref'], $plan);
        }

        $manager->flush();
    }
}
