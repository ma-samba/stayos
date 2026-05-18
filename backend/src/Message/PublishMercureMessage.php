<?php

namespace App\Message;

/** @todo Sprint 2 — Publication d'événements SSE via Mercure */
final class PublishMercureMessage
{
    public function __construct(
        public readonly string $topic,
        public readonly array  $data,
    ) {}
}
