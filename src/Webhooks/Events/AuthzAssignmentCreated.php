<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\AuthzAssignmentWebhookData;

/** `authz.assignment.created`: listen with `Event::listen(AuthzAssignmentCreated::class, …)`. */
final class AuthzAssignmentCreated
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly AuthzAssignmentWebhookData $data,
    ) {}
}
