<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Platform\Auth\Domain\Entity\StaffUser;
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

class SubscriptionLimitCheckerTest extends TestCase
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

        $this->checker->assertCanAddUser();
    }

    public function testEnterprisePlanIsUnlimited(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('ENTERPRISE');
        $plan->setMaxUsers(null);
        $plan->setPriceXof('75000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        // Aucun appel à createQueryBuilder ne doit être nécessaire :
        // l'early return après le check `maxUsers === null` suffit.
        $this->em->expects(self::never())->method('createQueryBuilder');

        $this->checker->assertCanAddUser(); // ne doit pas lever
    }

    public function testThrowsWhenLimitReached(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('STARTER');
        $plan->setMaxUsers(5);
        $plan->setPriceXof('15000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        $this->mockCounts(activeStaff: 4, pendingInvitations: 1); // = 5

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/Limite du plan STARTER/');

        $this->checker->assertCanAddUser();
    }

    public function testPassesWhenBelowLimit(): void
    {
        $tenant = new Tenant();
        $this->tenantContext->method('get')->willReturn($tenant);

        $plan = new Plan();
        $plan->setName('STARTER');
        $plan->setMaxUsers(5);
        $plan->setPriceXof('15000.00');

        $subscription = new Subscription();
        $subscription->setPlan($plan);
        $subscription->setStatus('active');

        $this->subRepo->method('findActiveByTenant')->willReturn($subscription);

        $this->mockCounts(activeStaff: 3, pendingInvitations: 1); // = 4 < 5

        $this->checker->assertCanAddUser(); // ne doit pas lever
        self::assertTrue(true);
    }

    /**
     * Construit deux QueryBuilder mockés successifs : le premier renvoie
     * `activeStaff`, le second `pendingInvitations`. Reflète l'ordre des
     * appels dans `SubscriptionLimitChecker::assertCanAddUser`.
     */
    private function mockCounts(int $activeStaff, int $pendingInvitations): void
    {
        $staffQuery = $this->createMock(Query::class);
        $staffQuery->method('getSingleScalarResult')->willReturn($activeStaff);

        $invQuery = $this->createMock(Query::class);
        $invQuery->method('getSingleScalarResult')->willReturn($pendingInvitations);

        $staffQb = $this->createMock(QueryBuilder::class);
        $staffQb->method('select')->willReturnSelf();
        $staffQb->method('from')->willReturnSelf();
        $staffQb->method('where')->willReturnSelf();
        $staffQb->method('getQuery')->willReturn($staffQuery);

        $invQb = $this->createMock(QueryBuilder::class);
        $invQb->method('select')->willReturnSelf();
        $invQb->method('from')->willReturnSelf();
        $invQb->method('where')->willReturnSelf();
        $invQb->method('setParameter')->willReturnSelf();
        $invQb->method('getQuery')->willReturn($invQuery);

        // 1er createQueryBuilder() → staff, 2e → invitations
        $this->em->method('createQueryBuilder')
            ->willReturnOnConsecutiveCalls($staffQb, $invQb);
    }
}
