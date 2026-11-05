<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\AuthzCatalogChangedWebhookData;

/** `authz.catalog.changed`: listen with `Event::listen(AuthzCatalogChanged::class, …)`. */
final class AuthzCatalogChanged
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly AuthzCatalogChangedWebhookData $data,
    ) {}
}
