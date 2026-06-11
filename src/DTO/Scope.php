<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * An IAM scope. Wire shape of `@b1-road/types/iam` `Scope`.
 */
final class Scope extends Data
{
    /** @param  array<string,mixed>  $metadata */
    public function __construct(
        public string $id,
        public string $type,
        public ?string $externalId = null,
        public ?string $parentScopeId = null,
        public ?ParentScopeRef $parentScope = null,
        public array $metadata = [],
        public string $createdAt = '',
        public ?string $updatedAt = null,
    ) {}
}
