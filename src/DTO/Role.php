<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use B1Road\Laravel\Client\Resources\RoleWire;
use Spatie\LaravelData\Data;

/**
 * An IAM role. Wire shape of `@b1-road/types` `Role`. The API answers with a
 * nullable `description` and an optional `createdAt`; hydrate via
 * {@see RoleWire} which normalises those.
 */
final class Role extends Data
{
    /** @param  list<string>  $permissions */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $permissions,
        public bool $isSystem,
        public int $assignmentCount,
        public string $createdAt,
    ) {}
}
