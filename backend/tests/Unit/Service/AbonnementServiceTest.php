<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Domain\Enum\SaasInvoiceStatus;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Domain\Service\SaasInvoiceService;
use App\Platform\Subscription\Domain\Service\SubscriptionEmailService;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AbonnementServiceTest extends TestCase
{
    private AbonnementService $service;
    private MockObject&EntityManagerInterface $em;
    private MockObject&SubscriptionRepository $subRepo;
    private MockObject&SaasInvoiceRepository  $invoiceRepo;
    private MockObject&TenantRepository       $tenantRepo;
    private MockObject&SaasInvoiceService     $saasInvoiceService;
    private MockObject&SubscriptionEmailService $emailService;
    private MockObject&LoggerInterface        $logger;

    protected function setUp(): void
    {
        $this->em                 = $this->createMock(EntityManagerInterface::class);
        $this->subRepo            = $this->createMock(SubscriptionRepository::class);
        $this->invoiceRepo        = $this->createMock(SaasInvoiceRepository::class);
        $this->tenantRepo         = $this->createMock(TenantRepository::class);
        $this->saasInvoiceService = $this->createMock(SaasInvoiceService::class);
        $this->emailService       = $this->createMock(SubscriptionEmailService::class);
        $this->logger             = $this->createMock(LoggerInterface::class);

        $this->service = new AbonnementService(
            $this->em,
            $this->subRepo,
            $this->invoiceRepo,
            $this->tenantRepo,
            $this->saasInvoiceService,
            $this->emailService,
            $this->logger,
        );
    }

    public function testCreateTrialSetsExpirationCorrectly(): void
    {
        $tenant = $this->makeTenant('savana');
        $plan   = $this->makePlan('STARTER');

        $this->em->expects(self::once())->method('persist')->with(self::isInstanceOf(Subscription::class));
        $this->em->expects(self::once())->method('flush');

        $sub = $this->service->createTrial($tenant, $plan, 14);

        self::assertSame('trial', $sub->getStatus());
        self::assertSame($plan, $sub->getPlan());
        self::assertNotNull($sub->getTrialEndsAt());

        $now      = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
        $daysAway = (int) $now->diff($sub->getTrialEndsAt())->format('%a');
        self::assertGreaterThanOrEqual(13, $daysAway);
        self::assertLessThanOrEqual(15, $daysAway);
    }

    public function testUpgradeFromTrialActivatesImmediately(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::TRIAL);

        $oldPlan = $this->makePlan('STARTER');
        $newPlan = $this->makePlan('PRO');

        $existing = new Subscription();
        $existing->setTenant($tenant);
        $existing->setPlan($oldPlan);
        $existing->setStatus('trial');
        $existing->setTrialEndsAt(new \DateTimeImmutable('+5 days'));

        $this->subRepo->method('findActiveByTenant')->with($tenant)->willReturn($existing);
        $this->em->expects(self::once())->method('flush');

        $sub = $this->service->upgrade($tenant, $newPlan);

        self::assertSame('active', $sub->getStatus());
        self::assertSame($newPlan, $sub->getPlan());
        self::assertNotNull($sub->getCurrentPeriodStart());
        self::assertNotNull($sub->getCurrentPeriodEnd());
        self::assertNull($sub->getTrialEndsAt());
        self::assertSame(TenantStatus::ACTIVE->value, $tenant->getStatus());
    }

    public function testUpgradeFromActiveKeepsPeriodDates(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::ACTIVE);

        $oldPlan = $this->makePlan('STARTER');
        $newPlan = $this->makePlan('PRO');

        $start = new \DateTimeImmutable('2026-06-01');
        $end   = new \DateTimeImmutable('2026-06-30');

        $existing = new Subscription();
        $existing->setTenant($tenant);
        $existing->setPlan($oldPlan);
        $existing->setStatus('active');
        $existing->setCurrentPeriodStart($start);
        $existing->setCurrentPeriodEnd($end);

        $this->subRepo->method('findActiveByTenant')->willReturn($existing);

        $sub = $this->service->upgrade($tenant, $newPlan);

        self::assertSame('active', $sub->getStatus());
        self::assertSame($newPlan, $sub->getPlan());
        // V1 : pas de prorata, on garde la période en cours.
        self::assertSame($start, $sub->getCurrentPeriodStart());
        self::assertSame($end, $sub->getCurrentPeriodEnd());
    }

    public function testCancelDoesNotSuspendImmediately(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::ACTIVE);

        $existing = new Subscription();
        $existing->setTenant($tenant);
        $existing->setPlan($this->makePlan('PRO'));
        $existing->setStatus('active');
        $existing->setCurrentPeriodEnd(new \DateTimeImmutable('+10 days'));

        $this->subRepo->method('findActiveByTenant')->willReturn($existing);

        $sub = $this->service->cancel($tenant);

        self::assertSame('cancelled', $sub->getStatus());
        self::assertNotNull($sub->getCancelledAt());
        // Tenant reste accessible jusqu'à expiration.
        self::assertSame(TenantStatus::ACTIVE->value, $tenant->getStatus());
    }

    public function testCheckExpirationsSuspendsExpiredTrial(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::TRIAL);

        $sub = new Subscription();
        $sub->setTenant($tenant);
        $sub->setPlan($this->makePlan('STARTER'));
        $sub->setStatus('trial');
        $sub->setTrialEndsAt(new \DateTimeImmutable('-1 day'));

        $this->mockSubscriptionScan([$sub]);

        $this->emailService->expects(self::once())
            ->method('sendTrialExpired')
            ->with($sub)
            ->willReturn(true);

        $stats = $this->service->checkExpirations();

        self::assertSame(TenantStatus::SUSPENDED->value, $tenant->getStatus());
        self::assertSame(1, $stats['processed']);
        self::assertSame(1, $stats['suspended']);
        self::assertSame(1, $stats['emailed']);
        self::assertSame(0, $stats['errors']);
        self::assertSame(AbonnementService::NOTIF_TRIAL_OVER, $sub->getLastNotificationType());
    }

    public function testCheckExpirationsRenewsActivePaidSubscription(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::ACTIVE);

        $sub = new Subscription();
        $sub->setTenant($tenant);
        $sub->setPlan($this->makePlan('PRO', '35000.00'));
        $sub->setStatus('active');
        $sub->setCurrentPeriodEnd(new \DateTimeImmutable('-1 day'));

        $this->mockSubscriptionScan([$sub]);

        // Pas de facture ouverte → on en génère une, puis charge OK.
        $this->invoiceRepo->method('findOpenForSubscription')->willReturn(null);

        $invoice = new SaasInvoice();
        $invoice->setTenant($tenant);
        $invoice->setSubscription($sub);
        $invoice->setPlanName('PRO');
        $invoice->setAmountXof('35000.00');
        $invoice->setPeriodStart(new \DateTimeImmutable('-1 day'));
        $invoice->setPeriodEnd(new \DateTimeImmutable('+30 days'));
        $invoice->setNumber('SAAS-2026-00001');

        $this->saasInvoiceService->expects(self::once())
            ->method('generateForPeriod')
            ->with($sub)
            ->willReturn($invoice);

        $this->saasInvoiceService->expects(self::once())
            ->method('charge')
            ->with($invoice)
            ->willReturn(true);

        $this->saasInvoiceService->expects(self::once())
            ->method('markSent')
            ->with($invoice);

        $this->emailService->expects(self::once())
            ->method('sendPaymentLink')
            ->with($invoice)
            ->willReturn(true);

        $stats = $this->service->checkExpirations();

        self::assertSame(1, $stats['invoiced']);
        self::assertSame(1, $stats['emailed']);
        self::assertSame(0, $stats['suspended']);
        // Tenant n'est PAS suspendu — il a 7 jours pour payer.
        self::assertSame(TenantStatus::ACTIVE->value, $tenant->getStatus());
    }

    public function testCheckExpirationsSuspendsOnUnpaidInvoiceOverdue(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::ACTIVE);

        $sub = new Subscription();
        $sub->setTenant($tenant);
        $sub->setPlan($this->makePlan('PRO'));
        $sub->setStatus('active');
        $sub->setCurrentPeriodEnd(new \DateTimeImmutable('-10 days'));

        $invoice = new SaasInvoice();
        $invoice->setTenant($tenant);
        $invoice->setSubscription($sub);
        $invoice->setPlanName('PRO');
        $invoice->setAmountXof('35000.00');
        $invoice->setPeriodStart(new \DateTimeImmutable('-10 days'));
        $invoice->setPeriodEnd(new \DateTimeImmutable('+20 days'));
        $invoice->setDueAt(new \DateTimeImmutable('-2 days'));
        $invoice->setNumber('SAAS-2026-00042');
        $invoice->setStatus(SaasInvoiceStatus::PENDING);

        $this->mockSubscriptionScan([$sub]);
        $this->invoiceRepo->method('findOpenForSubscription')->willReturn($invoice);

        $this->saasInvoiceService->expects(self::once())
            ->method('markFailed')
            ->with($invoice);

        $this->emailService->expects(self::once())
            ->method('sendPaymentFailed')
            ->with($invoice)
            ->willReturn(true);

        $stats = $this->service->checkExpirations();

        self::assertSame(TenantStatus::SUSPENDED->value, $tenant->getStatus());
        self::assertSame(1, $stats['suspended']);
        self::assertSame(1, $stats['emailed']);
        self::assertSame(0, $stats['invoiced']);
    }

    public function testCheckExpirationsIsolatesErrorsPerTenant(): void
    {
        // tenant A en trial expiré → email OK
        // tenant B en trial expiré → email échoue avec une exception
        // tenant C en trial expirant dans 7j → email OK
        $a = $this->trialExpired('a');
        $b = $this->trialExpired('b');
        $c = $this->trialExpiringIn('c', 5);

        $this->mockSubscriptionScan([$a, $b, $c]);

        // emailService::sendTrialExpired() lèvera pour b
        $this->emailService->method('sendTrialExpired')
            ->willReturnCallback(function (Subscription $sub) {
                if ($sub->getTenant()->getSlug() === 'b') {
                    throw new \RuntimeException('Mailjet down');
                }
                return true;
            });

        $this->emailService->method('sendTrialExpiring7d')->willReturn(true);

        $stats = $this->service->checkExpirations();

        // Les 3 tenants ont été traités. b est en erreur mais a et c ont continué.
        self::assertSame(3, $stats['processed']);
        self::assertSame(1, $stats['errors']);
        // a et c ont envoyé un email
        self::assertSame(2, $stats['emailed']);
        // Tenant a doit être suspendu, c doit rester accessible
        self::assertSame(TenantStatus::SUSPENDED->value, $a->getTenant()->getStatus());
        self::assertNotSame(TenantStatus::SUSPENDED->value, $c->getTenant()->getStatus());
    }

    public function testTrialNotificationsAreIdempotent(): void
    {
        $tenant = $this->makeTenant('savana');
        $tenant->setStatus(TenantStatus::TRIAL);

        $sub = new Subscription();
        $sub->setTenant($tenant);
        $sub->setPlan($this->makePlan('STARTER'));
        $sub->setStatus('trial');
        $sub->setTrialEndsAt(new \DateTimeImmutable('+5 days'));
        $sub->setLastNotificationType(AbonnementService::NOTIF_TRIAL_7D);

        $this->mockSubscriptionScan([$sub]);

        // Email ne doit PAS être envoyé : le type 7d a déjà été notifié.
        $this->emailService->expects(self::never())->method('sendTrialExpiring7d');

        $stats = $this->service->checkExpirations();

        self::assertSame(0, $stats['emailed']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function makeTenant(string $slug): Tenant
    {
        $t = new Tenant();
        $t->setSlug($slug);
        $t->setName(ucfirst($slug));
        $t->setSubdomain($slug);
        return $t;
    }

    private function makePlan(string $name, string $priceXof = '15000.00'): Plan
    {
        $p = new Plan();
        $p->setName($name);
        $p->setPriceXof($priceXof);
        $p->setFeatures([]);
        return $p;
    }

    private function trialExpired(string $slug): Subscription
    {
        $t = $this->makeTenant($slug);
        $t->setStatus(TenantStatus::TRIAL);
        $s = new Subscription();
        $s->setTenant($t);
        $s->setPlan($this->makePlan('STARTER'));
        $s->setStatus('trial');
        $s->setTrialEndsAt(new \DateTimeImmutable('-1 day'));
        return $s;
    }

    private function trialExpiringIn(string $slug, int $days): Subscription
    {
        $t = $this->makeTenant($slug);
        $t->setStatus(TenantStatus::TRIAL);
        $s = new Subscription();
        $s->setTenant($t);
        $s->setPlan($this->makePlan('STARTER'));
        $s->setStatus('trial');
        $s->setTrialEndsAt(new \DateTimeImmutable("+{$days} days"));
        return $s;
    }

    /**
     * @param Subscription[] $subs
     */
    private function mockSubscriptionScan(array $subs): void
    {
        // QueryBuilder::getQuery() est typé Query (concret) — on stubbe la
        // classe en désactivant son constructeur pour éviter une vraie EM.
        $qb = $this->createMock(QueryBuilder::class);

        $query = $this->getMockBuilder(\Doctrine\ORM\Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getResult'])
            ->getMock();

        $repo = $this->createMock(EntityRepository::class);

        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn($subs);

        $repo->method('createQueryBuilder')->willReturn($qb);

        $this->em->method('getRepository')
            ->with(Subscription::class)
            ->willReturn($repo);
    }
}
