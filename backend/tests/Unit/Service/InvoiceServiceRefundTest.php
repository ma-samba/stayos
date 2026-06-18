<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Hotel\Billing\Application\DTO\RefundDTO;
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

class InvoiceServiceRefundTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditService&MockObject $audit;
    private MercurePublisher&MockObject $mercure;
    private InvoiceService $service;
    private StaffUser&MockObject $staff;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->mercure = $this->createMock(MercurePublisher::class);
        $this->audit   = $this->createMock(AuditService::class);
        $twig          = $this->createMock(Twig::class);
        $logger        = new NullLogger();

        // Simule UuidV4Generator de Doctrine : l'ID est assigné dès persist().
        // Indispensable pour que (string) $refund->getId() (passé à AuditService)
        // ne déclenche pas "must not be accessed before initialization".
        $this->em->method('persist')->willReturnCallback(function (object $entity): void {
            if ($entity instanceof Payment) {
                $ref = new \ReflectionProperty(Payment::class, 'id');
                if (!$ref->isInitialized($entity)) {
                    $ref->setValue($entity, Uuid::v4());
                }
            }
        });

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
        $this->staff = $this->createMock(StaffUser::class);
    }

    public function testRefundAmountStoredAsNegative(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '100000.00');

        $dto = $this->makeDto('30000', 'wave', 'Geste commercial smoke');

        $refund = $this->service->refundPayment($invoice, $dto, $this->staff);

        self::assertSame('-30000.00', $refund->getAmountXof());
        self::assertSame(PaymentStatus::PAID, $refund->getStatusEnum());
        self::assertStringContainsString('Geste commercial smoke', $refund->getNotes() ?? '');
    }

    public function testRefundReducesPaidXofAccordingly(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '100000.00');

        $dto = $this->makeDto('40000', 'cash', 'Trop encaissé');
        $this->service->refundPayment($invoice, $dto, $this->staff);

        // 100000 - 40000 = 60000 effectivement payés (net)
        self::assertSame('60000.00', $invoice->getPaidXof());
        // Balance = total - paid = 40000
        self::assertSame('40000.00', $invoice->getBalanceXof());
    }

    public function testRefundFullPaymentReturnsStatusToIssued(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '100000.00');

        $dto = $this->makeDto('100000', 'wave', 'Annulation totale');
        $this->service->refundPayment($invoice, $dto, $this->staff);

        self::assertSame(InvoiceStatus::ISSUED, $invoice->getStatusEnum());
        self::assertSame('0.00', $invoice->getPaidXof());
        self::assertSame('100000.00', $invoice->getBalanceXof());
    }

    public function testPartialRefundFromPaidReturnsStatusToPartial(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '100000.00');

        $dto = $this->makeDto('25000', 'cash', 'Geste commercial 25%');
        $this->service->refundPayment($invoice, $dto, $this->staff);

        self::assertSame(InvoiceStatus::PARTIAL, $invoice->getStatusEnum());
        self::assertSame('75000.00', $invoice->getPaidXof());
        self::assertSame('25000.00', $invoice->getBalanceXof());
    }

    public function testRefundOnCancelledKeepsCancelled(): void
    {
        // Facture annulée mais qui avait été partiellement payée
        $invoice = $this->makeInvoice(InvoiceStatus::CANCELLED, '100000.00', paid: '50000.00');

        $dto = $this->makeDto('50000', 'cash', 'Remboursement post-annulation');
        $this->service->refundPayment($invoice, $dto, $this->staff);

        self::assertSame(InvoiceStatus::CANCELLED, $invoice->getStatusEnum());
        // Le paidXof net est bien tombé à 0 mais le statut reste CANCELLED
        self::assertSame('0.00', $invoice->getPaidXof());
    }

    public function testRefundLogsAuditWithRealEntityId(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '100000.00');

        // Capture l'entityId passé à AuditService::log
        $capturedEntityId   = null;
        $capturedEntityType = null;
        $this->audit->expects(self::once())->method('log')
            ->willReturnCallback(function (
                string $action,
                string $entityType,
                string $entityId,
            ) use (&$capturedEntityId, &$capturedEntityType): void {
                $capturedEntityId   = $entityId;
                $capturedEntityType = $entityType;
            });

        $dto    = $this->makeDto('30000', 'wave', 'Geste commercial');
        $refund = $this->service->refundPayment($invoice, $dto, $this->staff);

        self::assertNotSame('new', $capturedEntityId, "L'audit ne doit plus contenir le marqueur littéral 'new'");
        self::assertNotSame('', $capturedEntityId);
        self::assertSame((string) $refund->getId(), $capturedEntityId);
        self::assertSame('Payment', $capturedEntityType);
    }

    public function testRefundExceedingPaidThrowsBusinessRule(): void
    {
        $invoice = $this->makeInvoice(InvoiceStatus::PAID, '100000.00', paid: '50000.00');

        $this->expectException(BusinessRuleException::class);
        $this->expectExceptionMessageMatches('/seulement.*50000\.00/');

        $dto = $this->makeDto('60000', 'wave', 'Tentative over-refund');
        $this->service->refundPayment($invoice, $dto, $this->staff);
    }

    // ── Helpers ────────────────────────────────────────────────

    private function makeDto(string $amount, string $method, string $reason): RefundDTO
    {
        $dto = new RefundDTO();
        $dto->amountXof = $amount;
        $dto->method    = $method;
        $dto->reason    = $reason;
        return $dto;
    }

    /**
     * Construit une Invoice avec un paiement PAID initial pour
     * simuler un état où le client a payé.
     */
    private function makeInvoice(InvoiceStatus $status, string $totalXof, string $paid): Invoice
    {
        $invoice = new Invoice();
        $invoice->setNumber('FAC-TEST-' . substr(uniqid('', true), -5));
        $invoice->setSubtotalXof($totalXof);
        $invoice->setTaxRate('0.00');
        $invoice->setTaxXof('0.00');
        $invoice->setTotalXof($totalXof);
        $invoice->setStatusEnum($status);

        $guest = $this->createMock(Guest::class);
        $guest->method('getFullName')->willReturn('Test Guest');
        $reservation = $this->createMock(Reservation::class);
        $reservation->method('getGuest')->willReturn($guest);
        $invoice->setReservation($reservation);

        $idRef = new \ReflectionProperty(Invoice::class, 'id');
        $idRef->setValue($invoice, Uuid::v4());

        if (bccomp($paid, '0', 2) > 0) {
            $payment = new Payment();
            $payment->setInvoice($invoice);
            $payment->setMethodEnum(\App\Hotel\Billing\Domain\Enum\PaymentMethod::WAVE);
            $payment->setAmountXof($paid);
            $payment->setStatusEnum(PaymentStatus::PAID);
            $payment->setPaidAt(new \DateTimeImmutable('yesterday'));
            $payIdRef = new \ReflectionProperty(Payment::class, 'id');
            $payIdRef->setValue($payment, Uuid::v4());
            $invoice->addPayment($payment);
        }

        return $invoice;
    }
}
