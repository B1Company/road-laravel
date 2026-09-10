<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\BridgeGrantWebhookData;

/** `bridge.grant.created` — listen with `Event::listen(BridgeGrantCreated::class, …)`. */
final class BridgeGrantCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly BridgeGrantWebhookData $data,
    ) {}
}
