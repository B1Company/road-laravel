<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * One row of a platform's own Bridge audit. Wire shape of `@b1-road/types/bridge`
 * `PlatformBridgeAuditEntry`: the other platform and the decision, nothing about
 * the other side's people or Road's internals.
 */
final class BridgeAuditEntry extends Data
{
    public function __construct(
        /** `exchange`, `check`, `attempt`, `grant_created` or `grant_revoked`. */
        public string $event,
        /** Null when Road can no longer resolve the other platform. */
        public ?BridgeAuditCounterparty $counterparty,
        /** The permission an `attempt` was about; null for every other event. */
        public ?string $permission,
        public bool $allowed,
        /** A short, stable code, e.g. `tenant_denied` or `provider_denied`. */
        public string $reason,
        public string $createdAt,
    ) {}
}
