<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `bridge.grant.{created,revoked}`, as the API delivers it.
 *
 * **Subscribe to these if you cache authorization answers.** Road's check API
 * is authoritative on every call, but SDK middleware caches its result per
 * token, so without these events a revoked grant keeps being honoured until
 * the cache TTL expires.
 *
 * ⚠️ There is deliberately **no** `businessUnitId` here, and adding one would
 * be a security change, not a convenience. A Bridge grant is cross-platform,
 * so a business unit on this payload would route it through the BU fan-out to
 * every co-subscribed platform — disclosing that platform A holds a role at
 * provider B to unrelated third parties (the API-F4 class of leak). The
 * absence is the contract; see B1-450.
 */
final class BridgeGrantWebhookData extends Data
{
    public function __construct(
        /** The provider the grant is at — your platform's `publicId` (`plat_…`). */
        public string $providerPublicId,
        /** The consumer platform the grant is for (`plat_…`). */
        public string $subjectPlatformPublicId,
        /** The role the grant holds (`role_…`). */
        public string $roleId,
        /** That role's name. */
        public string $roleName,
        /** The assignment behind the grant (`asg_…`). */
        public string $grantId,
    ) {}
}
