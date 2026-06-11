<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `organization.member.{joined,suspended,reinstated,removed}`.
 * Wire shape of `@b1-road/types` `MemberWebhookData`.
 */
final class MemberWebhookData extends Data
{
    public function __construct(
        public string $businessUnitId,
        public string $memberId,
        public string $userId,
    ) {}
}
