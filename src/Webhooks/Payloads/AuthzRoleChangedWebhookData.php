<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `authz.role.changed`: a role of your platform's catalog changed,
 * so its holders may have too. Wire shape of `@b1-road/types`
 * `AuthzRoleChangedWebhookData`.
 */
final class AuthzRoleChangedWebhookData extends Data
{
    public function __construct(
        public string $platformPublicId,
        /** The role (`role_…`). */
        public string $roleId,
        /** `composition`, `renamed` or `deleted`. */
        public string $change,
    ) {}
}
