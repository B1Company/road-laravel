<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Assignment;
use B1Road\Laravel\DTO\EffectivePermissionsResult;

/**
 * A single scope: its roles, permissions, assignments and effective-permission
 * queries. Returned by `Road::client()->iam()->scope($scopeId)`.
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

    /** What is available at this scope, inherited rows included. */
    public function permissions(): PermissionCollection
    {
        return new PermissionCollection($this->http, $this->path().'/permissions');
    }

    public function assignments(): AssignmentCollection
    {
        return new AssignmentCollection($this->http, $this->path().'/assignments');
    }

    /**
     * Assign a role at this scope. Assigning a role the subject already holds
     * here returns the existing assignment.
     *
     * @param  array{subjectType:string, subjectId:string, roleId:string, expiresAt?:string}  $input
     */
    public function assign(array $input): Assignment
    {
        return Assignment::from($this->unwrap(
            $this->http->request('POST', $this->path().'/assignments', $input),
        ));
    }

    /**
     * Assign up to 100 roles at once. All of them land, or none does.
     *
     * @param  list<array{subjectType:string, subjectId:string, roleId:string, expiresAt?:string}>  $assignments
     * @return list<array{id:string, created:bool}>
     */
    public function assignMany(array $assignments): array
    {
        $body = $this->http->request('POST', $this->path().'/assignments/batch', ['assignments' => $assignments]);

        $out = [];
        foreach ((array) ($body['data'] ?? []) as $row) {
            if (is_array($row)) {
                $out[] = ['id' => (string) ($row['id'] ?? ''), 'created' => ($row['created'] ?? false) === true];
            }
        }

        return $out;
    }

    public function effectivePermissions(string $subjectType, string $subjectId): EffectivePermissionsResult
    {
        $body = $this->http->request(
            'GET',
            '/authz/subjects/'.rawurlencode($subjectType).'/'.rawurlencode($subjectId).'/permissions',
            null,
            ['scopeId' => $this->scopeId],
        );

        return EffectivePermissionsResult::from($this->unwrap($body));
    }

    private function path(): string
    {
        return '/authz/scopes/'.rawurlencode($this->scopeId);
    }
}
