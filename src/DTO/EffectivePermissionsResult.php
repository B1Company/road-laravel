<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * What a subject may do at a scope. `codes` has `manage` expanded. `all` is a
 * holder of every code there, and `codes` is then empty.
 */
final class EffectivePermissionsResult extends Data
{
    /** @param  list<string>  $codes */
    public function __construct(
        public string $scopeId,
        public bool $all,
        public array $codes,
    ) {}
}
