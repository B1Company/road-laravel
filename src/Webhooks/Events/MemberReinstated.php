<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use B1Road\Laravel\Webhooks\Payloads\MemberWebhookData;

/** `organization.member.reinstated` — listen with `Event::listen(MemberReinstated::class, …)`. */
final class MemberReinstated
{
    public function __construct(
        public readonly string $id,
        public readonly string $timestamp,
        public readonly MemberWebhookData $data,
    ) {}
}
