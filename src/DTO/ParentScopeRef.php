<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * The lightweight parent-scope reference embedded in a {@see Scope}.
 */
final class ParentScopeRef extends Data
{
    public function __construct(
        public string $id,
        public string $type,
        public ?string $externalId = null,
    ) {}
}
