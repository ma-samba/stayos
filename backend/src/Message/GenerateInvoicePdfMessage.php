<?php

namespace App\Message;

/** @todo Sprint 4 — Async PDF generation via Dompdf */
final class GenerateInvoicePdfMessage
{
    public function __construct(
        public readonly string $invoiceId,
    ) {}
}
