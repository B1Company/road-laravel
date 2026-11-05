<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * One permission of a catalog, addressed by public id (`perm_…`). Wire shape of
 * `@b1-road/types` `AuthzPermission`.
 */
final class AuthzPermission extends Data
{
    public function __construct(
        public string $id,
        /** `action:Subject`. Fixed once created. */
        public string $code,
        public string $name,
        public ?string $description,
        /** Free-text label used to group rows in a picker. */
        public ?string $category,
        /** `customer` or `staff`. */
        public string $audience,
        /** The scope that owns this row. */
        public string $scopeId,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
