<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\DTO\Role;

/**
 * Wire→domain translator for roles. The API answers with a nullable
 * `description` and an optional `createdAt` (plus scope/metadata fields the
 * SDK omits), so translate in one place rather than loosening the DTO.
 * Mirrors `apiRoleToRole` in `apps/sdks/road-nestjs/src/client/resources/wire.ts`.
 */
final class RoleWire
{
    /** @param  array<string,mixed>  $wire */
    public static function toDomain(array $wire): Role
    {
        $permissions = [];
        foreach ((array) ($wire['permissions'] ?? []) as $permission) {
            if (is_string($permission)) {
                $permissions[] = $permission;
            }
        }

        return new Role(
            id: (string) ($wire['id'] ?? ''),
            name: (string) ($wire['name'] ?? ''),
            description: (string) ($wire['description'] ?? ''),
            permissions: $permissions,
            isSystem: (bool) ($wire['isSystem'] ?? false),
            assignmentCount: (int) ($wire['assignmentCount'] ?? 0),
            createdAt: (string) ($wire['createdAt'] ?? ''),
        );
    }
}
