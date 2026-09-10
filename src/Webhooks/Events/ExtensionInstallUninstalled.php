<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\ExtensionInstallWebhookData;

/** `extension.install.uninstalled` — listen with `Event::listen(ExtensionInstallUninstalled::class, …)`. */
final class ExtensionInstallUninstalled
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly ExtensionInstallWebhookData $data,
    ) {}
}
