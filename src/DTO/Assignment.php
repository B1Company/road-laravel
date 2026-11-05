<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/** A role held at a scope, addressed by public id (`asg_…`). */
final class Assignment extends Data
{
    public function __construct(
        public string $id,
        public string $subjectType,
        public string $subjectId,
        public string $roleId,
        public string $roleName,
        public string $scopeId,
        public string $grantedBy,
        public string $grantedAt,
        public ?string $expiresAt = null,
    ) {}
}
