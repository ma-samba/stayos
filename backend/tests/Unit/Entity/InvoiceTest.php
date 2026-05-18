<?php

namespace App\Tests\Unit\Entity;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use PHPUnit\Framework\TestCase;

class InvoiceTest extends TestCase
{
    private function makeInvoice(string $subtotal, string $taxRate = '18.00'): Invoice
    {
        $tax   = number_format((float) $subtotal * (float) $taxRate / 100, 2, '.', '');
        $total = number_format((float) $subtotal + (float) $tax, 2, '.', '');

        $invoice = new Invoice();
        $invoice->setNumber('FAC-TEST-001');
        $invoice->setSubtotalXof($subtotal);
        $invoice->setTaxRate($taxRate);
        $invoice->setTaxXof($tax);
        $invoice->setTotalXof($total);

        return $invoice;
    }

    public function testTaxCalculation18Percent(): void
    {
        $invoice = $this->makeInvoice('135000.00');
        // 135 000 × 18% = 24 300
        $this->assertSame('24300.00', $invoice->getTaxXof());
    }

    public function testTotalIncludesTax(): void
    {
        $invoice = $this->makeInvoice('135000.00');
        // 135 000 + 24 300 = 159 300
        $this->assertSame('159300.00', $invoice->getTotalXof());
    }

    public function testTaxOnOneNight(): void
    {
        $invoice = $this->makeInvoice('45000.00');
        // 45 000 × 18% = 8 100
        $this->assertSame('8100.00', $invoice->getTaxXof());
        // Total = 45 000 + 8 100 = 53 100
        $this->assertSame('53100.00', $invoice->getTotalXof());
    }

    public function testTaxOnSuite(): void
    {
        $invoice = $this->makeInvoice('285000.00');
        // 285 000 × 18% = 51 300
        $this->assertSame('51300.00', $invoice->getTaxXof());
        // Total = 285 000 + 51 300 = 336 300
        $this->assertSame('336300.00', $invoice->getTotalXof());
    }

    public function testDefaultStatusIsDraft(): void
    {
        $invoice = new Invoice();
        $this->assertSame('draft', $invoice->getStatus());
    }

    public function testDefaultTaxRateIs18(): void
    {
        $invoice = new Invoice();
        $this->assertSame('18.00', $invoice->getTaxRate());
    }

    public function testStatusEnumRoundtrip(): void
    {
        $invoice = new Invoice();
        $invoice->setStatusEnum(InvoiceStatus::PAID);
        $this->assertSame('paid', $invoice->getStatus());
        $this->assertSame(InvoiceStatus::PAID, $invoice->getStatusEnum());
    }

    public function testCreatedAtIsSetOnConstruct(): void
    {
        $before  = new \DateTimeImmutable();
        $invoice = new Invoice();
        $after   = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $invoice->getCreatedAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $invoice->getCreatedAt()->getTimestamp());
    }
}
