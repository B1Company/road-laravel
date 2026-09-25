<?php

declare(strict_types=1);

namespace B1Road\Laravel\Bridge;

/**
 * Fired every time the `road.bridge` middleware refuses a request. Wire it to
 * your logger or metrics with `Event::listen(BridgeAccessDenied::class, …)`.
 * The Laravel counterpart of `bridgeEnforce({ onDeny })` in the Node SDKs.
 */
final class BridgeAccessDenied
{
    public function __construct(
        /** The reason code in the response body, e.g. `cross_tenant`. */
        public readonly string $reason,
        /** The permission the route required, when it named one. */
        public readonly ?string $permission,
        /** SHA-256 of the presented token: correlates denials without logging the bearer. */
        public readonly ?string $tokenDigest,
    ) {}
}
