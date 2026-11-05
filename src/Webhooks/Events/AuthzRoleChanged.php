<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\AuthzRoleChangedWebhookData;

/** `authz.role.changed`: listen with `Event::listen(AuthzRoleChanged::class, …)`. */
final class AuthzRoleChanged
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly AuthzRoleChangedWebhookData $data,
    ) {}
}
