<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/** A scope, as `GET /authz/scopes/:id` returns it. Its `id` is `scope_…`. */
final class Scope extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $externalId,
        public ?string $parentScopeId,
        /** The scope whose catalog decides a code here. */
        public string $catalogScopeId,
        public string $createdAt,
    ) {}
}
