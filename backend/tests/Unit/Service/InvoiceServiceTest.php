<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Application\DTO\RecordPaymentDTO;
use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Billing\Domain\Service\InvoiceService;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Hotel\NightAudit\Domain\Service\DailyCloseLockChecker;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Mercure\MercurePublisher;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;
use Twig\Environment as Twig;

class InvoiceServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditService&MockObject $audit;
    private MercurePublisher&MockObject $mercure;
    private InvoiceService $service;
    private StaffUser&MockObject $staff;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->audit   = $this->createMock(AuditService::class);
        $this->mercure = $this->createMock(MercurePublisher::class);
        $twig          = $this->createMock(Twig::class);
        $logger        = new NullLogger();

        $lockChecker         = $this->createMock(DailyCloseLockChecker::class);
        $businessDateService = $this->createMock(BusinessDateService::class);
        $businessDateService->method('getCurrentBusinessDate')->willReturn(
            new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar'))
        );

        $this->service = new InvoiceService(
            $this->em,
            $this->audit,
            $this->mercure,
            $twig,
            $lockChecker,
            $businessDateService,
            $logger,
        );
        $this->staff   = $this->createMock(StaffUser::class);
    }

    private function makeInvoice(InvoiceStatus $status, string $totalXof = '100000.00'): Invoice
    {
        $subtotal = $totalXof;
        $invoice  = new Invoice();
        $invoice->setNumber('FAC-TEST-001');
        $invoice->setSubtotalXof($subtotal);
        $invoice->setTaxRate('0.00');
        $invoice->setTaxXof('0.00');
        $invoice->setTotalXof($totalXof);
        $invoice->setStatusEnum($status);

        $guest = $this->createMock(Guest::class);
        $guest->method('getFullName')->willReturn('Test Guest');
        $reservation = $this->createMock(Reservation::class);
        $reservation->method('getGuest')->willReturn($guest);
        $invoice->setReservation($reservation);

        // Set UUID via reflection (normally Doctrine sets this)
        $ref = new \ReflectionProperty(Invoice::class, 'id');
        $ref->setValue($invoice, Uuid::v4());

        return $invoice;
    }

    // ── issue() ──

    public function testIssueDraftInvoice(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::DRAFT);

        $this->em->expects($this->once())->method('flush');
        $this->audit->expects($this->once())->method('log');

        $this->service->issue($invoice, $this->staff);

        $this->assertSame(InvoiceStatus::ISSUED, $invoice->getStatusEnum());
        $this->assertNotNull($invoice->getIssuedAt());
    }

    public function testIssueAlreadyIssuedThrowsBusinessRuleException(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::ISSUED);

        $this->expectException(BusinessRuleException::class);
        $this->service->issue($invoice, $this->staff);
    }

    public function testIssuePaidInvoiceThrowsBusinessRuleException(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID);

        $this->expectException(BusinessRuleException::class);
        $this->service->issue($invoice, $this->staff);
    }

    // ── recordPayment() ──

    public function testRecordPaymentMarksPaidWhenFullyPaid(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::ISSUED, '100000.00');

        $dto = new RecordPaymentDTO();
        $dto->method    = 'cash';
        $dto->amountXof = '100000.00';

        $this->em->expects($this->once())->method('persist')
            ->with($this->isInstanceOf(Payment::class));
        $this->em->expects($this->once())->method('flush');

        $payment = $this->service->recordPayment($invoice, $dto, $this->staff);

        $this->assertSame(PaymentStatus::PAID, $payment->getStatusEnum());
        $this->assertSame('100000.00', $payment->getAmountXof());
        $this->assertSame(InvoiceStatus::PAID, $invoice->getStatusEnum());
    }

    public function testRecordPaymentPartial(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::ISSUED, '100000.00');

        $dto = new RecordPaymentDTO();
        $dto->method    = 'cash';
        $dto->amountXof = '40000.00';

        $payment = $this->service->recordPayment($invoice, $dto, $this->staff);

        $this->assertSame('40000.00', $payment->getAmountXof());
        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->getStatusEnum());
        $this->assertFalse($invoice->isFullyPaid());
    }

    public function testRecordPaymentOnCancelledThrowsBusinessRuleException(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::CANCELLED, '100000.00');

        $dto = new RecordPaymentDTO();
        $dto->method    = 'cash';
        $dto->amountXof = '50000.00';

        $this->expectException(BusinessRuleException::class);
        $this->service->recordPayment($invoice, $dto, $this->staff);
    }
}
