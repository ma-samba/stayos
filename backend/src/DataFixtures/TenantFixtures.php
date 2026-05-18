<?php

namespace App\DataFixtures;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Domain\Service\TenantProvisioner;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class TenantFixtures extends Fixture implements DependentFixtureInterface
{
    public const SAVANA         = 'tenant-savana';
    public const VILLA_COLLINES = 'tenant-villa-collines';

    public function __construct(
        private readonly TenantProvisioner $tenantProvisioner,
    ) {}

    public function getDependencies(): array
    {
        return [PlanFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        // ── Hôtel Savana (Plan PRO, ACTIVE) ──
        $savana = new Tenant();
        $savana->setSlug('savana');
        $savana->setName('Hôtel Savana Dakar');
        $savana->setSubdomain('savana');
        $savana->setStatus(TenantStatus::ACTIVE);

        $manager->persist($savana);
        $manager->flush();

        $this->tenantProvisioner->provision($savana);

        /** @var Plan $planPro */
        $planPro = $this->getReference(PlanFixtures::PRO, Plan::class);

        $subSavana = new Subscription();
        $subSavana->setTenant($savana);
        $subSavana->setPlan($planPro);
        $subSavana->setStatus('active');
        $subSavana->setCurrentPeriodStart(new \DateTimeImmutable('first day of this month', $tz));
        $subSavana->setCurrentPeriodEnd(new \DateTimeImmutable('last day of this month', $tz));

        $manager->persist($subSavana);

        // ── Villa Collines (Plan STARTER, TRIAL) ──
        $villa = new Tenant();
        $villa->setSlug('villa-collines');
        $villa->setName('Villa Collines Saly');
        $villa->setSubdomain('villa-collines');
        $villa->setStatus(TenantStatus::ACTIVE);

        $manager->persist($villa);
        $manager->flush();

        $this->tenantProvisioner->provision($villa);

        /** @var Plan $planStarter */
        $planStarter = $this->getReference(PlanFixtures::STARTER, Plan::class);

        $subVilla = new Subscription();
        $subVilla->setTenant($villa);
        $subVilla->setPlan($planStarter);
        $subVilla->setStatus('active');
        $subVilla->setCurrentPeriodStart(new \DateTimeImmutable('first day of this month', $tz));
        $subVilla->setCurrentPeriodEnd(new \DateTimeImmutable('last day of this month', $tz));

        $manager->persist($subVilla);
        $manager->flush();

        $this->addReference(self::SAVANA, $savana);
        $this->addReference(self::VILLA_COLLINES, $villa);
    }
}
