<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `organization.member.role-changed`. Wire shape of
 * `@b1-road/types` `MemberRoleChangedWebhookData` (`MemberWebhookData` plus
 * the role and the direction of the change).
 */
final class MemberRoleChangedWebhookData extends Data
{
    public function __construct(
        public string $businessUnitId,
        public string $memberId,
        public string $userId,
        public string $roleId,
        /** @var 'assigned'|'revoked' */
        public string $action,
    ) {}
}
