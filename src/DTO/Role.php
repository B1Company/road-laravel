<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A role, addressed by public id (`role_…`). Wire shape of `@b1-road/types`
 * `AuthzRole`. It carries counts, not codes: read a role's composition with
 * `roles()->permissions($roleId)`.
 */
final class Role extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $description,
        /** `customer` or `staff`. */
        public string $audience,
        /** Holds every code of its catalog. Read-only. */
        public bool $grantsAll,
        /** Created at a business unit or subscription, instead of by a catalog. */
        public bool $custom,
        /** The scope that owns this row. */
        public string $scopeId,
        public int $permissionCount,
        /** How many live assignments deleting this role would revoke. */
        public int $assignmentCount,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
