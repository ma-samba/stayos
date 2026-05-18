<?php

namespace App\Message;

/** @todo Sprint 4 — KnpSnappy PDF generation */
final class GenerateInvoicePdfMessage
{
    public function __construct(
        public readonly string $invoiceId,
    ) {}
}
