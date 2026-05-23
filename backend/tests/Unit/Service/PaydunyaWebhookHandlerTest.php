<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Billing\Domain\Gateway\PaymentConfirmation;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayInterface;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayRegistry;
use App\Hotel\Billing\Domain\Service\InvoiceService;
use App\Hotel\Billing\Domain\Service\PaydunyaWebhookHandler;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Email\EmailService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class PaydunyaWebhookHandlerTest extends TestCase
{
    private PaymentGatewayRegistry&MockObject $gatewayRegistry;
    private TenantRepository&MockObject $tenantRepository;
    private EntityManagerInterface&MockObject $em;
    private Connection&MockObject $connection;
    private InvoiceService&MockObject $invoiceService;
    private EmailService&MockObject $emailService;
    private PaydunyaWebhookHandler $handler;

    private const SECRET     = 'correct_secret_abc123';
    private const TENANT     = 'savana';
    private const TOKEN      = 'paydunya_token_xyz';
    private const GATEWAY    = 'paydunya';

    protected function setUp(): void
    {
        $this->gatewayRegistry = $this->createMock(PaymentGatewayRegistry::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->connection = $this->createMock(Connection::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->emailService = $this->createMock(EmailService::class);

        $this->handler = new PaydunyaWebhookHandler(
            $this->gatewayRegistry,
            $this->tenantRepository,
            $this->em,
            $this->connection,
            $this->invoiceService,
            $this->emailService,
            new NullLogger(),
        );
    }

    // ── Helpers ──

    private function makeTenant(): Tenant&MockObject
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getSchemaName')->willReturn('hotel_abc123def');
        $tenant->method('getSlug')->willReturn(self::TENANT);

        return $tenant;
    }

    private function makePayment(
        string $amount = '100000.00',
        PaymentStatus $status = PaymentStatus::PENDING,
    ): Payment {
        $invoice = new Invoice();
        $invoice->setNumber('FAC-TEST-001');
        $invoice->setSubtotalXof($amount);
        $invoice->setTaxRate('0.00');
        $invoice->setTaxXof('0.00');
        $invoice->setTotalXof($amount);
        $invoice->setStatusEnum(InvoiceStatus::ISSUED);

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setMethodEnum(PaymentMethod::MOBILE_MONEY);
        $payment->setAmountXof($amount);
        $payment->setStatusEnum($status);
        $payment->setCallbackSecret(self::SECRET);
        $payment->setGatewayToken(self::TOKEN);
        $payment->setGatewayName(self::GATEWAY);

        $invoice->addPayment($payment);

        // Set a UUID id via reflection (normally Doctrine sets this)
        $ref = new \ReflectionProperty(Payment::class, 'id');
        $ref->setValue($payment, Uuid::v4());

        return $payment;
    }

    private function makePayload(): array
    {
        return [
            'invoice' => [
                'token'  => self::TOKEN,
                'status' => 'completed',
            ],
        ];
    }

    private function stubTenantResolution(): void
    {
        $this->tenantRepository->method('findActiveBySlug')
            ->with(self::TENANT)
            ->willReturn($this->makeTenant());

        // search_path calls: SET ... and restore
        $this->connection->expects($this->exactly(2))
            ->method('executeStatement');
    }

    private function stubPaymentLookup(Payment $payment): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')
            ->with(['gatewayToken' => self::TOKEN])
            ->willReturn($payment);

        $this->em->method('getRepository')
            ->with(Payment::class)
            ->willReturn($repo);
    }

    private function stubGatewayConfirmation(
        bool $ok = true,
        string $status = 'completed',
        ?int $amount = 100000,
        array $raw = [],
    ): void {
        $confirmation = new PaymentConfirmation($ok, $status, $amount, $raw);
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('confirmPayment')->willReturn($confirmation);
        $gateway->method('getName')->willReturn(self::GATEWAY);

        $this->gatewayRegistry->method('get')
            ->with(self::GATEWAY)
            ->willReturn($gateway);
    }

    private function stubTransaction(): void
    {
        // wrapInTransaction simply executes the callback
        $this->em->method('wrapInTransaction')
            ->willReturnCallback(fn (callable $fn) => $fn());
    }

    private function stubLockedFind(Payment $payment): void
    {
        $this->em->method('find')
            ->willReturn($payment);
    }

    // ── Tests ──

    public function testValidIpnMarksPaymentPaid(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation(true, 'completed', 100000, ['payment_method' => 'wave']);
        $this->stubTransaction();
        $this->stubLockedFind($payment);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentStatus::PAID, $payment->getStatusEnum());
        $this->assertNotNull($payment->getPaidAt());
        // Wave resolved from raw payload
        $this->assertSame(PaymentMethod::WAVE, $payment->getMethodEnum());
        // Invoice should be PAID (single payment covers full amount)
        $this->assertSame(InvoiceStatus::PAID, $payment->getInvoice()->getStatusEnum());
    }

    public function testInvalidSecretIgnored(): void
    {
        $payment = $this->makePayment();
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);

        // Gateway confirmPayment should NEVER be called when secret is wrong
        $this->gatewayRegistry->expects($this->never())->method('get');

        $this->handler->handle($this->makePayload(), 'wrong_secret', self::TENANT);

        $this->assertSame(PaymentStatus::PENDING, $payment->getStatusEnum());
    }

    public function testIdempotencyAlreadyPaid(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PAID);
        $payment->setPaidAt(new \DateTimeImmutable('2026-05-20'));
        $originalPaidAt = $payment->getPaidAt();

        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation();
        $this->stubTransaction();
        $this->stubLockedFind($payment);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        // Payment stays PAID, paidAt unchanged (transaction callback exits early)
        $this->assertSame(PaymentStatus::PAID, $payment->getStatusEnum());
        $this->assertSame($originalPaidAt, $payment->getPaidAt());
    }

    public function testMissingTokenIgnored(): void
    {
        $this->stubTenantResolution();

        // No repository call expected — early return before lookup
        $this->em->expects($this->never())->method('getRepository');

        $this->handler->handle(['invoice' => []], self::SECRET, self::TENANT);
    }

    public function testPaymentNotFoundIgnored(): void
    {
        $this->stubTenantResolution();

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $this->em->method('getRepository')->willReturn($repo);

        // Gateway should never be called
        $this->gatewayRegistry->expects($this->never())->method('get');

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);
    }

    public function testAmountMismatchMarksFailed(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        // Gateway returns a DIFFERENT amount (anti-fraude)
        $this->stubGatewayConfirmation(true, 'completed', 50000);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentStatus::FAILED, $payment->getStatusEnum());
    }

    public function testConfirmationNotCompletedMarksFailed(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation(false, 'cancelled');

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentStatus::FAILED, $payment->getStatusEnum());
    }

    public function testTenantNotFoundReturnsEarly(): void
    {
        $this->tenantRepository->method('findActiveBySlug')->willReturn(null);

        // No search_path should be set
        $this->connection->expects($this->never())->method('executeStatement');

        $this->handler->handle($this->makePayload(), self::SECRET, 'unknown');
    }

    // ── resolvePaymentMethod (tested indirectly via full flow) ──

    public function testResolvePaymentMethodWave(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation(true, 'completed', 100000, ['payment_method' => 'wave-senegal']);
        $this->stubTransaction();
        $this->stubLockedFind($payment);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentMethod::WAVE, $payment->getMethodEnum());
    }

    public function testResolvePaymentMethodOrangeMoney(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation(true, 'completed', 100000, ['payment_method' => 'orange-money-senegal']);
        $this->stubTransaction();
        $this->stubLockedFind($payment);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentMethod::ORANGE_MONEY, $payment->getMethodEnum());
    }

    public function testResolvePaymentMethodCard(): void
    {
        $payment = $this->makePayment('100000.00', PaymentStatus::PENDING);
        $this->stubTenantResolution();
        $this->stubPaymentLookup($payment);
        $this->stubGatewayConfirmation(true, 'completed', 100000, ['payment_method' => 'visa']);
        $this->stubTransaction();
        $this->stubLockedFind($payment);

        $this->handler->handle($this->makePayload(), self::SECRET, self::TENANT);

        $this->assertSame(PaymentMethod::CARD, $payment->getMethodEnum());
    }
}
