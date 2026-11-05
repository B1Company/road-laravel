<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Role;

/**
 * Roles, which live under a scope (not directly under a BU). Listing and
 * creating go through `/authz/scopes/{scopeId}/roles`, reached two ways:
 *
 *   Road::client()->businessUnits($buId)->roles()   // resolves the BU's scope
 *   Road::client()->iam()->scope($scopeId)->roles() // direct
 *
 * When constructed for a BU, the scope id is resolved from the BU detail once
 * and memoised. A single role is addressed by its own id (`role_…`), so
 * `get()`, `update()` and `delete()` need no scope.
 *
 * @extends Paginates<Role>
 */
final class RoleCollection extends Paginates
{
    use UnwrapsData;

    private function __construct(
        HttpTransportInterface $http,
        private readonly ?string $buId,
        private ?string $scopeId,
    ) {
        parent::__construct($http);
    }

    public static function forBusinessUnit(HttpTransportInterface $http, string $buId): self
    {
        return new self($http, $buId, null);
    }

    public static function forScope(HttpTransportInterface $http, string $scopeId): self
    {
        return new self($http, null, $scopeId);
    }

    public function get(string $roleId): Role
    {
        return Role::from($this->unwrap($this->http->request('GET', $this->rolePath($roleId))));
    }

    /**
     * Create a role at the scope. `permissionIds` (`perm_…`) or
     * `cloneFromRoleId` (`role_…`) say what it starts with.
     *
     * @param  array<string,mixed>  $input
     */
    public function create(array $input): Role
    {
        return Role::from($this->unwrap($this->http->request('POST', $this->rolesPath(), $input)));
    }

    /** @param  array<string,mixed>  $input */
    public function update(string $roleId, array $input): Role
    {
        return Role::from($this->unwrap($this->http->request('PATCH', $this->rolePath($roleId), $input)));
    }

    /** Delete a role. Returns how many assignments ended with it. */
    public function delete(string $roleId): int
    {
        $body = $this->http->request('DELETE', $this->rolePath($roleId));

        return (int) ($this->unwrap($body)['assignmentsRevoked'] ?? 0);
    }

    /** What a role composes. */
    public function permissions(string $roleId): PermissionCollection
    {
        return new PermissionCollection($this->http, $this->rolePath($roleId).'/permissions');
    }

    /** Add one permission (`perm_…`) to a role. */
    public function compose(string $roleId, string $permissionId): Role
    {
        $body = $this->http->request('POST', $this->rolePath($roleId).'/permissions', ['permissionId' => $permissionId]);

        return Role::from($this->unwrap($body));
    }

    /** Take one permission (`perm_…`) out of a role. */
    public function decompose(string $roleId, string $permissionId): Role
    {
        $body = $this->http->request('DELETE', $this->rolePath($roleId).'/permissions/'.rawurlencode($permissionId));

        return Role::from($this->unwrap($body));
    }

    protected function listPath(): string
    {
        return $this->rolesPath();
    }

    protected function mapRow(array $row): Role
    {
        return Role::from($row);
    }

    private function rolesPath(): string
    {
        return '/authz/scopes/'.rawurlencode($this->scopeId()).'/roles';
    }

    private function rolePath(string $roleId): string
    {
        return '/authz/roles/'.rawurlencode($roleId);
    }

    /**
     * Resolve (and memoise) the scope id. When this collection was built
     * for a BU, fetch the BU detail once to read its `iamScopeId`.
     */
    private function scopeId(): string
    {
        if ($this->scopeId !== null) {
            return $this->scopeId;
        }

        $body = $this->http->request('GET', '/organization/business-units/'.rawurlencode((string) $this->buId));

        return $this->scopeId = (string) ($this->unwrap($body)['iamScopeId'] ?? '');
    }
}
