<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\AuthzAssignmentWebhookData;

/** `authz.assignment.revoked`: listen with `Event::listen(AuthzAssignmentRevoked::class, …)`. */
final class AuthzAssignmentRevoked
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly AuthzAssignmentWebhookData $data,
    ) {}
}
