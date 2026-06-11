<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for every `organization.invitation.*` event. Wire shape of
 * `@b1-road/types` `InvitationWebhookData`.
 */
final class InvitationWebhookData extends Data
{
    public function __construct(
        public string $businessUnitId,
        public string $invitationId,
        public string $email,
    ) {}
}
