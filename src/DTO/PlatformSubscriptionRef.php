<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * A platform a business unit subscribes to, as embedded in a {@see Membership}.
 * Wire shape of `@b1-road/types` `PlatformSubscriptionRef`.
 */
final class PlatformSubscriptionRef extends Data
{
    /**
     * @param  DataCollection<int, RoleRef>  $roles  Roles the current user holds
     *                                               on this platform scope; empty if they have a grant here but no named role.
     */
    public function __construct(
        /** Stable platform public id, e.g. "plat_payment_gw_seed01". */
        public string $platformId,
        /** Human-readable platform identifier, e.g. "payment-gateway". */
        public string $slug,
        /** IAM scope id for this BU's subscription to the platform. */
        public string $scopeId,
        #[DataCollectionOf(RoleRef::class)]
        public DataCollection $roles,
    ) {}
}
