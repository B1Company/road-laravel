<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `authz.catalog.changed`: your platform's permissions changed. One
 * event per write or import. Wire shape of `@b1-road/types`
 * `AuthzCatalogChangedWebhookData`.
 */
final class AuthzCatalogChangedWebhookData extends Data
{
    public function __construct(
        public string $platformPublicId,
    ) {}
}
