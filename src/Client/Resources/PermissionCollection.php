<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\AuthzPermission;

/**
 * Permissions, at a scope or in a role. Iterable (auto-paginating) with a
 * `firstPage()` escape hatch. Their `perm_…` ids are what a custom role is
 * composed from.
 *
 * @extends Paginates<AuthzPermission>
 */
final class PermissionCollection extends Paginates
{
    public function __construct(HttpTransportInterface $http, private readonly string $path)
    {
        parent::__construct($http);
    }

    protected function listPath(): string
    {
        return $this->path;
    }

    protected function mapRow(array $row): AuthzPermission
    {
        return AuthzPermission::from($row);
    }
}
