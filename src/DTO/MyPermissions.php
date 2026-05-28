<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Permissions keyed by Business Unit id. Each value is the array of
 * "action:Subject" strings that the calling user has for that BU.
 *
 * Kept open-shaped for the MVP — the PermissionSet wrapper (follow-up)
 * gives type-safe predicates.
 */
final class MyPermissions extends Data
{
    /**
     * @param  array<string, list<string>>  $byBusinessUnit  e.g. `['bu_1' => ['read:Member', 'manage:Role']]`
     */
    public function __construct(
        public array $byBusinessUnit,
    ) {
    }
}
