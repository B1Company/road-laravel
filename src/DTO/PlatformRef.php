<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A lightweight platform reference carried on platform-scoped roles. Wire shape
 * of `@b1-road/types` `PlatformRef`.
 */
final class PlatformRef extends Data
{
    public function __construct(
        /** Stable platform public id, e.g. "plat_payment_gw_seed01". */
        public string $id,
        /** Human-readable platform identifier, e.g. "payment-gateway". */
        public string $slug,
    ) {}
}
