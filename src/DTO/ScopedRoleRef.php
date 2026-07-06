<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A role reference that may be scoped to a platform. Wire shape of
 * `@b1-road/types` `ScopedRoleRef` (extends `RoleRef` with an optional
 * `platform`). BU-scoped roles omit `platform`; platform-scoped roles carry it
 * so the UI can label them. Used by {@see Member::$roles}.
 */
final class ScopedRoleRef extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        /** Present only on platform-scoped roles. */
        public ?PlatformRef $platform = null,
    ) {}
}
