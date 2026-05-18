<?php

namespace App\Message;

/** @todo Sprint 5 — Channel Manager OTA sync */
final class SyncChannelMessage
{
    public function __construct(
        public readonly string $channel,
        public readonly string $tenantId,
    ) {}
}
