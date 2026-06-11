<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\EffectivePermissionsResult;

/**
 * A single IAM scope — its roles and effective-permission queries. Returned by
 * `Road::client()->iam()->scope($scopeId)`. Mirrors `IamScope` in road-nestjs.
 */
final class IamScope
{
    use UnwrapsData;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly string $scopeId,
    ) {}

    public function roles(): RoleCollection
    {
        return RoleCollection::forScope($this->http, $this->scopeId);
    }

    public function effectivePermissions(string $subjectType, string $subjectId): EffectivePermissionsResult
    {
        $body = $this->http->request(
            'GET',
            '/iam/authorization/subjects/'.rawurlencode($subjectType).'/'.rawurlencode($subjectId).'/permissions',
            null,
            ['scopeId' => $this->scopeId],
        );
        $data = $this->unwrap($body);

        $permissions = [];
        foreach ((array) ($data['permissions'] ?? []) as $permission) {
            if (is_string($permission)) {
                $permissions[] = $permission;
            }
        }

        return new EffectivePermissionsResult(permissions: $permissions);
    }
}
