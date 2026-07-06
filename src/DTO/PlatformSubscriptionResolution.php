<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Resolves a business unit's subscription to a platform by the platform's
 * public id. Wire shape of `@b1-road/types` `PlatformSubscriptionResolution`
 * (`GET /organization/business-units/{buId}/subscriptions/{platformPublicId}`).
 */
final class PlatformSubscriptionResolution extends Data
{
    public function __construct(
        public string $subscriptionId,
        public string $platformId,
        public string $slug,
        public string $scopeId,
    ) {}
}
