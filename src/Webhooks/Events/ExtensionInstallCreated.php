<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\ExtensionInstallWebhookData;

/** `extension.install.created` — listen with `Event::listen(ExtensionInstallCreated::class, …)`. */
final class ExtensionInstallCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly ExtensionInstallWebhookData $data,
    ) {}
}
