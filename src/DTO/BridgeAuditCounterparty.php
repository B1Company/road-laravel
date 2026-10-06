<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * The other platform in a Bridge audit row. Wire shape of `@b1-road/types/bridge`
 * `BridgeAuditCounterparty`.
 */
final class BridgeAuditCounterparty extends Data
{
    public function __construct(
        /** The platform's public id (`plat_…`). */
        public string $id,
        public string $name,
    ) {}
}
