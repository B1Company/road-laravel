<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\InvitationWebhookData;

/** `organization.invitation.rejected` — listen with `Event::listen(InvitationRejected::class, …)`. */
final class InvitationRejected
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly InvitationWebhookData $data,
    ) {}
}
