<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Effective permissions for a subject on a scope — already flattened to
 * `"action:Subject"` strings (`manage:X` expanded server-side). Wire shape of
 * `@b1-road/types/iam` `EffectivePermissionsResult`.
 */
final class EffectivePermissionsResult extends Data
{
    /** @param  list<string>  $permissions */
    public function __construct(
        public array $permissions,
    ) {}
}
