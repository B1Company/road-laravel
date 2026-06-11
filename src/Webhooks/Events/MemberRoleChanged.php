<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\MemberRoleChangedWebhookData;

/** `organization.member.role-changed` — listen with `Event::listen(MemberRoleChanged::class, …)`. */
final class MemberRoleChanged
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly MemberRoleChangedWebhookData $data,
    ) {}
}
