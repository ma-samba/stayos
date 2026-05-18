<?php

namespace App\Message;

/** @todo Sprint 3 — Implémenter avec template, destinataire, contexte */
final class SendEmailMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $subject,
        public readonly string $template,
        public readonly array  $context = [],
    ) {}
}
