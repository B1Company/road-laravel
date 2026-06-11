<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A role assignment. Wire shape of `@b1-road/types/iam` `Assignment`. The
 * list endpoint enriches rows with `roleName` / `scopeType` /
 * `scopeExternalId`; those are nullable here since the create/get paths omit
 * them.
 */
final class Assignment extends Data
{
    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectId,
        public string $roleId,
        public string $scopeId,
        public string $grantedBy,
        public string $grantedAt,
        public ?string $expiresAt = null,
        public ?string $roleName = null,
        public ?string $scopeType = null,
        public ?string $scopeExternalId = null,
    ) {}
}
