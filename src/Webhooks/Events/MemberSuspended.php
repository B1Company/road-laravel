<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\MemberWebhookData;

/** `organization.member.suspended` — listen with `Event::listen(MemberSuspended::class, …)`. */
final class MemberSuspended
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly MemberWebhookData $data,
    ) {}
}
