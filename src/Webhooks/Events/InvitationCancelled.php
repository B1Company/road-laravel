<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\InvitationWebhookData;

/** `organization.invitation.cancelled` — listen with `Event::listen(InvitationCancelled::class, …)`. */
final class InvitationCancelled
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly InvitationWebhookData $data,
    ) {}
}
