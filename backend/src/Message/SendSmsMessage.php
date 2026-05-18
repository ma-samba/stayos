<?php

namespace App\Message;

/** @todo Sprint 4 — Implémenter avec provider SMS */
final class SendSmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $body,
    ) {}
}
