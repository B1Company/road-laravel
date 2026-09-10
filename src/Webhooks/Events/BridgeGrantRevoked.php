<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\BridgeGrantWebhookData;

/** `bridge.grant.revoked` — listen with `Event::listen(BridgeGrantRevoked::class, …)`. */
final class BridgeGrantRevoked
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly BridgeGrantWebhookData $data,
    ) {}
}
