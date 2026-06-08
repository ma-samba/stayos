<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Domain\Service\SubscriptionLimitChecker;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 13ter — tests miroirs de SubscriptionLimitCheckerTest mais
 * pour `assertCanAddRoom()` (limite maxRooms).
 */
class SubscriptionLimitCheckerRoomTest extends TestCase
{
    private SubscriptionLimitChecker $checker;
    private MockObject&SubscriptionRepository $subRepo;
    private MockObject&EntityManagerInterface $em;
    private MockObject&TenantContext          $tenantContext;

    protected function setUp(): void
    {
        $this->subRepo       = $this->createMock(SubscriptionRepository::class);
        $this->em            = $this->createMock(EntityManagerInterface::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->checker = new SubscriptionLimitChecker(
            $this->subRepo,
            $this->em,
            $this->tenantContext,
        );
    }

    public function testThrowsWhenNoActiveSubscription(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);
        $this->subRepo->method('findActiveByTenant')->with($tenant)->willReturn(null);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/abonnement actif/i');

        $this->checker->assertCanAddRoom();
    }

    public function testEnterprisePlanIsUnlimited(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('ENTERPRISE');
        $plan->setMaxRooms(null); // illimité
        $plan->setPriceXof('75000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        // Aucun decompte attendu : early return.
        $this->em->expects(self::never())->method('createQueryBuilder');

        $this->checker->assertCanAddRoom(); // ne doit pas lever
        self::assertTrue(true);
    }

    public function testThrowsWhenLimitReached(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('STARTER');
        $plan->setMaxRooms(20);
        $plan->setPriceXof('15000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        $this->mockRoomCount(20);

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Limite du plan STARTER/');

        $this->checker->assertCanAddRoom();
    }

    public function testPassesWhenBelowLimit(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('STARTER');
        $plan->setMaxRooms(20);
        $plan->setPriceXof('15000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        $this->mockRoomCount(12);

        $this->checker->assertCanAddRoom(); // ne doit pas lever
        self::assertTrue(true);
    }

    private function mockRoomCount(int $active): void
    {
        $query = $this->createMock(Query::class);
        $query->method('getSingleScalarResult')->willReturn($active);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->em->method('createQueryBuilder')->willReturn($qb);
    }
}
