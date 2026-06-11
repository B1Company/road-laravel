<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\InvitationWebhookData;

/** `organization.invitation.accepted` — listen with `Event::listen(InvitationAccepted::class, …)`. */
final class InvitationAccepted
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly InvitationWebhookData $data,
    ) {}
}
