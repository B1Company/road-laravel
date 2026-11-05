<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * The caller's codes, `manage` expanded, `*` for a role that holds them all.
 * `byBusinessUnit` is keyed by BU id and carries Road's codes at the unit.
 * `byScope` adds each subscription, where your platform's codes live: a
 * membership's `platformSubscriptions` name those scope ids.
 *
 * Kept open-shaped for the MVP. The PermissionSet wrapper (follow-up) gives
 * type-safe predicates.
 */
final class MyPermissions extends Data
{
    /**
     * @param  array<string, list<string>>  $byBusinessUnit  e.g. `['bu_1' => ['read:Member', 'update:Role']]`
     * @param  array<string, list<string>>  $byScope  e.g. `['scope_1' => ['read:Invoice']]`
     */
    public function __construct(
        public array $byBusinessUnit,
        public array $byScope = [],
    ) {}
}
