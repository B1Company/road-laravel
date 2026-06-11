<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Role;

/**
 * Roles, which live under an IAM scope (not directly under a BU). Reachable
 * two ways, both landing on `/iam/authorization/scopes/{scopeId}/roles`:
 *
 *   Road::client()->businessUnits($buId)->roles()   // resolves the BU's scope
 *   Road::client()->iam()->scope($scopeId)->roles() // direct
 *
 * When constructed for a BU, the scope id is resolved from the BU detail once
 * and memoised. Mirrors `RoleCollection` / `makeRoleCollection` in road-nestjs.
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
        $body = $this->http->request('GET', $this->rolesPath().'/'.rawurlencode($roleId));

        return RoleWire::toDomain($this->unwrap($body));
    }

    /** @param  array<string,mixed>  $input */
    public function create(array $input): Role
    {
        $body = $this->http->request('POST', $this->rolesPath(), $input);

        return RoleWire::toDomain($this->unwrap($body));
    }

    /** @param  array<string,mixed>  $input */
    public function update(string $roleId, array $input): Role
    {
        $body = $this->http->request('PATCH', $this->rolesPath().'/'.rawurlencode($roleId), $input);

        return RoleWire::toDomain($this->unwrap($body));
    }

    public function delete(string $roleId): void
    {
        $this->http->request('DELETE', $this->rolesPath().'/'.rawurlencode($roleId));
    }

    protected function listPath(): string
    {
        return $this->rolesPath();
    }

    protected function mapRow(array $row): Role
    {
        return RoleWire::toDomain($row);
    }

    private function rolesPath(): string
    {
        return '/iam/authorization/scopes/'.rawurlencode($this->scopeId()).'/roles';
    }

    /**
     * Resolve (and memoise) the IAM scope id. When this collection was built
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
