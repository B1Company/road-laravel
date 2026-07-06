<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Authorization\Permission;
use B1Road\Laravel\Exceptions\RoadNotFoundException;

/**
 * In-memory router for fake Road API calls. Port of
 * `apps/sdks/road-nestjs/src/testing/in-memory-backend.ts`.
 *
 * Matches `method + path` against a set of handlers and serves data from the
 * test scenario. The authorize verdict logic mirrors Road's real engine for
 * the basic case — `manage:X` expands to CRUD, `*` is wildcard. Read paths
 * (members, roles, invitations, effective permissions) are served from the
 * scenario; mutations acknowledge with a plausible echo so an integrator's
 * code under test runs without the network.
 */
final class InMemoryBackend
{
    /** @var list<array{method:string, path:string, body:?array<string,mixed>}> */
    public array $calls = [];

    public function __construct(public readonly RoadScenario $scenario) {}

    /**
     * @param  array<string,mixed>|null  $body
     * @param  string|null  $userId  Hint of which user is calling.
     * @param  array<string,scalar|null>|null  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $method, string $path, ?array $body = null, ?string $userId = null, ?array $query = null): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');

        // ── Identity & self ────────────────────────────────────────────────
        if ($method === 'GET' && $path === '/iam/identity/me') {
            $user = $userId !== null ? $this->scenario->findUser($userId) : null;
            if ($user === null) {
                throw new RoadNotFoundException('No active user in scenario.');
            }

            return ['status' => 200, 'body' => ['data' => $user]];
        }

        if ($method === 'GET' && $path === '/me/business-units') {
            $memberships = $userId !== null ? $this->scenario->memberships($userId)['memberships'] : [];

            return ['status' => 200, 'body' => [
                'data' => ['memberships' => $memberships, 'pendingInvitations' => []],
            ]];
        }

        if ($method === 'GET' && $path === '/iam/authorization/me/permissions') {
            return $this->handleMyPermissions($query ?? [], $userId);
        }

        // ── Authorization checks ───────────────────────────────────────────
        if ($method === 'POST' && $path === '/iam/authorization/authorize/batch') {
            return $this->handleAuthorizeBatch($body ?? [], $userId);
        }

        if ($method === 'POST' && $path === '/iam/authorization/authorize') {
            return $this->handleAuthorize($body ?? [], $userId);
        }

        // ── IAM control plane: scopes, roles, assignments ──────────────────
        if ($method === 'GET' && $path === '/iam/authorization/scopes/lookup') {
            return ['status' => 200, 'body' => ['data' => $this->synthScope('scope_lookup', [
                'type' => (string) ($query['type'] ?? 'business_unit'),
                'externalId' => isset($query['externalId']) ? (string) $query['externalId'] : null,
            ])]];
        }

        if ($method === 'POST' && $path === '/iam/authorization/scopes') {
            return ['status' => 201, 'body' => ['data' => $this->synthScope('scope_new', [
                'type' => (string) (($body['type'] ?? null) ?: 'business_unit'),
                'externalId' => isset($body['externalId']) ? (string) $body['externalId'] : null,
                'parentScopeId' => isset($body['parentScopeId']) ? (string) $body['parentScopeId'] : null,
                'metadata' => is_array($body['metadata'] ?? null) ? $body['metadata'] : [],
            ])]];
        }

        if ($method === 'GET' && preg_match('#^/iam/authorization/scopes/([^/]+)/roles$#', $path, $m) === 1) {
            return $this->paginate($this->rolesForScope($m[1]));
        }

        if ($method === 'POST' && preg_match('#^/iam/authorization/scopes/([^/]+)/roles$#', $path) === 1) {
            return ['status' => 201, 'body' => ['data' => $this->makeRole($body ?? [])]];
        }

        if (preg_match('#^/iam/authorization/scopes/([^/]+)/roles/([^/]+)$#', $path, $m) === 1) {
            if ($method === 'DELETE') {
                return ['status' => 200, 'body' => ['data' => []]];
            }
            if ($method === 'PATCH') {
                return ['status' => 200, 'body' => ['data' => $this->makeRole(array_merge(['name' => $m[2]], $body ?? []), $m[2])]];
            }
            // GET single role
            foreach ($this->rolesForScope($m[1]) as $role) {
                if ($role['id'] === $m[2]) {
                    return ['status' => 200, 'body' => ['data' => $role]];
                }
            }
            throw new RoadNotFoundException(sprintf('Role %s not in scenario.', $m[2]));
        }

        if ($method === 'GET' && preg_match('#^/iam/authorization/scopes/([^/]+)$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->synthScope($m[1])]];
        }

        if ($method === 'GET' && preg_match('#^/iam/authorization/subjects/([^/]+)/([^/]+)/permissions$#', $path, $m) === 1) {
            $scopeId = (string) ($query['scopeId'] ?? '');
            $bu = $this->resolveBuFromScope($scopeId);
            $perms = $bu !== null ? $this->scenario->effectivePermissions($m[2], (string) $bu['id']) : [];

            return ['status' => 200, 'body' => ['data' => ['permissions' => $perms]]];
        }

        if ($method === 'GET' && preg_match('#^/iam/authorization/subjects/([^/]+)/([^/]+)/assignments$#', $path) === 1) {
            return ['status' => 200, 'body' => ['data' => []]];
        }

        if ($method === 'POST' && $path === '/iam/authorization/assignments') {
            return ['status' => 201, 'body' => ['data' => $this->makeAssignment($body ?? [])]];
        }

        if ($method === 'DELETE' && preg_match('#^/iam/authorization/assignments/([^/]+)$#', $path) === 1) {
            return ['status' => 200, 'body' => ['data' => []]];
        }

        // ── Organization: members & invitations ────────────────────────────
        if (preg_match('#^/organization/business-units/([^/]+)/members/([^/]+)/(suspend|reinstate)$#', $path) === 1 && $method === 'POST') {
            return ['status' => 200, 'body' => ['data' => []]];
        }

        // Assign / revoke a role on a member (before the generic member DELETE
        // so `/roles/{roleId}` matches the specific handler first).
        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/members/([^/]+)/roles$#', $path) === 1) {
            return ['status' => 201, 'body' => ['message' => 'Role assigned']];
        }

        if ($method === 'DELETE' && preg_match('#^/organization/business-units/([^/]+)/members/([^/]+)/roles/([^/]+)$#', $path) === 1) {
            return ['status' => 200, 'body' => ['message' => 'Role revoked']];
        }

        if ($method === 'DELETE' && preg_match('#^/organization/business-units/([^/]+)/members/([^/]+)$#', $path) === 1) {
            return ['status' => 200, 'body' => ['data' => []]];
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)/subscriptions/([^/]+)$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => [
                'subscriptionId' => 'sub_'.$m[2],
                'platformId' => $m[2],
                'slug' => $m[2],
                'scopeId' => 'scope_'.$m[2],
            ]]];
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)/members/([^/]+)$#', $path, $m) === 1) {
            foreach ($this->membersForBu($m[1]) as $member) {
                if ($member['id'] === $m[2]) {
                    return ['status' => 200, 'body' => ['data' => $member]];
                }
            }
            throw new RoadNotFoundException(sprintf('Member %s not in scenario.', $m[2]));
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)/members$#', $path, $m) === 1) {
            return $this->paginate($this->membersForBu($m[1]));
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)/invitations$#', $path, $m) === 1) {
            return $this->paginate($this->scenario->invitationsByBu[$m[1]] ?? []);
        }

        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/invitations$#', $path) === 1) {
            return ['status' => 201, 'body' => ['data' => $this->makeInvitation(
                'inv_new',
                (string) ($body['email'] ?? ''),
                (string) ($body['roleId'] ?? 'r_member'),
                'pending',
            )]];
        }

        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/invitations/([^/]+)/cancel$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->transitionInvitation($m[2], 'cancelled')]];
        }

        if ($method === 'POST' && preg_match('#^/organization/invitations/([^/]+)/(accept|reject)$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->transitionInvitation($m[1], $m[2] === 'accept' ? 'accepted' : 'rejected')]];
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)$#', $path, $m) === 1) {
            $bu = $this->scenario->findBusinessUnit($m[1]);
            if ($bu === null) {
                throw new RoadNotFoundException(sprintf('Business unit %s not in scenario.', $m[1]));
            }

            return ['status' => 200, 'body' => ['data' => $bu]];
        }

        throw new RoadNotFoundException(sprintf('No fake handler for %s %s.', $method, $path));
    }

    /**
     * The caller is the token-derived user (`$userId`), mirroring the real API —
     * the SDK no longer forwards a `subjectId`.
     *
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleAuthorize(array $body, ?string $userId): array
    {
        $subjectId = (string) ($userId ?? '');
        $scopeId = (string) ($body['scopeId'] ?? '');
        $required = (string) ($body['permission'] ?? '');

        $verdict = $this->verdict($subjectId, $scopeId, $required);
        $bu = $this->resolveBuFromScope($scopeId);
        $rolesOnBu = $bu === null
            ? []
            : $this->rolesUserHasOnBu($subjectId, (string) $bu['id']);

        $reason = $verdict
            ? sprintf('granted: %s held by roles [%s]', $required, implode(', ', $rolesOnBu))
            : sprintf('no role grants %s', $required);

        // The real /authorize endpoint returns only { allowed, reason } — the
        // engine exposes neither evaluatedScopes nor a role-attributed
        // decision (NFR-14). The SDK builds its DecisionTrace from the
        // caller's effective permissions (/me/permissions), not from here.
        return ['status' => 200, 'body' => ['data' => [
            'allowed' => $verdict,
            'reason' => $reason,
        ]]];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleAuthorizeBatch(array $body, ?string $userId): array
    {
        $subjectId = (string) ($userId ?? '');
        $scopeId = (string) ($body['scopeId'] ?? '');
        /** @var list<mixed> $permissions */
        $permissions = (array) ($body['permissions'] ?? []);

        $results = [];
        foreach ($permissions as $perm) {
            if (! is_string($perm)) {
                continue;
            }
            $results[] = [
                'permission' => $perm,
                'allowed' => $this->verdict($subjectId, $scopeId, $perm),
            ];
        }

        return ['status' => 200, 'body' => ['data' => ['results' => $results]]];
    }

    /**
     * Scope-keyed effective-permissions handler. Honors the `scopes` CSV —
     * only answers for the requested scope ids — and returns `(action,
     * subject)` tuples per scope, the shape the real
     * `GET /iam/authorization/me/permissions` emits. Permissions are
     * returned as declared (manage→CRUD expansion is the API's job and is
     * exercised API-side, not here).
     *
     * @param  array<string,scalar|null>  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleMyPermissions(array $query, ?string $userId): array
    {
        // Single-scope mode (`?scope=`) → { data: { permissions: [tuples] } }.
        $single = isset($query['scope']) ? trim((string) $query['scope']) : '';
        if ($single !== '') {
            return ['status' => 200, 'body' => ['data' => [
                'permissions' => $this->tuplesForScope($single, $userId),
            ]]];
        }

        // Bulk mode (`?scopes=<csv>`) → { data: { <scopeId>: [tuples] } },
        // answering only for the requested scopes.
        $requested = array_values(array_filter(
            array_map('trim', explode(',', (string) ($query['scopes'] ?? ''))),
            static fn (string $s): bool => $s !== '',
        ));

        /** @var array<string, list<array{action:string, subject:string}>> $byScope */
        $byScope = [];
        foreach ($requested as $scopeId) {
            $byScope[$scopeId] = $this->tuplesForScope($scopeId, $userId);
        }

        return ['status' => 200, 'body' => ['data' => $byScope]];
    }

    /**
     * Effective `(action, subject)` tuples for a user on a scope id, as
     * declared in the scenario.
     *
     * @return list<array{action:string, subject:string}>
     */
    private function tuplesForScope(string $scopeId, ?string $userId): array
    {
        $bu = $this->resolveBuFromScope($scopeId);
        if ($bu === null || $userId === null) {
            return [];
        }

        return array_map(
            static function (string $p): array {
                if ($p === '*') {
                    return ['action' => '*', 'subject' => '*'];
                }
                $parts = explode(':', $p, 2);

                return ['action' => $parts[0] ?? '', 'subject' => $parts[1] ?? ''];
            },
            $this->scenario->effectivePermissions($userId, (string) $bu['id']),
        );
    }

    private function verdict(string $subjectId, string $scopeId, string $required): bool
    {
        $bu = $this->resolveBuFromScope($scopeId);
        if ($bu === null || $subjectId === '' || $required === '') {
            return false;
        }

        $effective = $this->scenario->effectivePermissions($subjectId, (string) $bu['id']);

        if (in_array(Permission::WILDCARD, $effective, true)) {
            return true;
        }

        // `manage:X` grants every action on X.
        $parts = explode(':', $required, 2);
        if (count($parts) === 2) {
            $managePermission = 'manage:'.$parts[1];
            if (in_array($managePermission, $effective, true)) {
                return true;
            }
        }

        return in_array($required, $effective, true);
    }

    /**
     * The real `/authorize` engine is keyed by the IAM **scope id**
     * (`scope_bu_1`), not the BU id. Match ONLY by `iamScopeId` so a test
     * catches an SDK that forgets to resolve `buId -> iamScopeId` before
     * authorizing (passing the BU id straight through denies everything in
     * production).
     *
     * @return array<string,mixed>|null
     */
    private function resolveBuFromScope(string $scopeId): ?array
    {
        if ($scopeId === '') {
            return null;
        }

        foreach ($this->scenario->businessUnits as $bu) {
            if (($bu['iamScopeId'] ?? null) === $scopeId) {
                return $bu;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function rolesUserHasOnBu(string $userId, string $buId): array
    {
        $memberships = $this->scenario->memberships($userId)['memberships'] ?? [];
        $roles = [];
        foreach ($memberships as $m) {
            if (($m['businessUnit']['id'] ?? null) !== $buId) {
                continue;
            }
            foreach ($m['roles'] ?? [] as $roleRef) {
                $name = (string) ($roleRef['name'] ?? '');
                if ($name !== '') {
                    $roles[] = $name;
                }
            }
        }

        return $roles;
    }

    /**
     * Members of a BU, derived from declared memberships so `members()`
     * listings work without a separate builder.
     *
     * @return list<array<string,mixed>>
     */
    private function membersForBu(string $buId): array
    {
        $members = [];
        foreach ($this->scenario->users as $uid => $user) {
            foreach ($this->scenario->memberships($uid)['memberships'] as $m) {
                if (($m['businessUnit']['id'] ?? null) !== $buId) {
                    continue;
                }
                $members[] = [
                    'id' => 'mem_'.$buId.'_'.$uid,
                    'userId' => $uid,
                    'status' => (string) ($m['status'] ?? 'active'),
                    'joinedAt' => (string) ($m['joinedAt'] ?? ''),
                    'name' => (string) ($user['name'] ?? $uid),
                    'email' => (string) ($user['email'] ?? ''),
                    'roles' => $m['roles'] ?? [],
                ];
            }
        }

        return $members;
    }

    /**
     * Roles defined on the BU behind a scope id (from `withRole`).
     *
     * @return list<array<string,mixed>>
     */
    private function rolesForScope(string $scopeId): array
    {
        $bu = $this->resolveBuFromScope($scopeId);
        if ($bu === null) {
            return [];
        }

        $roles = [];
        foreach ($this->scenario->rolesByBu[(string) $bu['id']] ?? [] as $name => $permissions) {
            $roles[] = $this->makeRole(['name' => $name, 'permissions' => array_values($permissions)], 'r_'.$name);
        }

        return $roles;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function makeRole(array $input, ?string $id = null): array
    {
        $name = (string) ($input['name'] ?? '');

        return [
            'id' => $id ?? ('r_'.($name !== '' ? $name : 'new')),
            'name' => $name,
            'description' => $input['description'] ?? null,
            'permissions' => array_values(array_filter((array) ($input['permissions'] ?? []), 'is_string')),
            'isSystem' => (bool) ($input['isSystem'] ?? false),
            'assignmentCount' => (int) ($input['assignmentCount'] ?? 0),
            'createdAt' => '2024-01-01T00:00:00Z',
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function makeAssignment(array $input): array
    {
        return [
            'id' => 'assign_new',
            'subjectType' => (string) ($input['subjectType'] ?? 'user'),
            'subjectId' => (string) ($input['subjectId'] ?? ''),
            'roleId' => (string) ($input['roleId'] ?? ''),
            'scopeId' => (string) ($input['scopeId'] ?? ''),
            'grantedBy' => 'fake',
            'grantedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => isset($input['expiresAt']) ? (string) $input['expiresAt'] : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function synthScope(string $scopeId, array $overrides = []): array
    {
        return array_merge([
            'id' => $scopeId,
            'type' => 'business_unit',
            'externalId' => null,
            'parentScopeId' => null,
            'parentScope' => null,
            'metadata' => [],
            'createdAt' => '2024-01-01T00:00:00Z',
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function makeInvitation(string $id, string $email, string $roleId, string $status): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'roleId' => $roleId,
            'roleName' => $roleId,
            'status' => $status,
            'acceptedVia' => $status === 'accepted' ? 'manual' : null,
            'invitedAt' => '2024-01-01T00:00:00Z',
            'expiresAt' => '2024-02-01T00:00:00Z',
        ];
    }

    /**
     * Find a declared invitation by id (across BUs) and return it with the
     * new status, synthesising one when the test didn't declare it.
     *
     * @return array<string,mixed>
     */
    private function transitionInvitation(string $id, string $status): array
    {
        foreach ($this->scenario->invitationsByBu as $list) {
            foreach ($list as $invitation) {
                if (($invitation['id'] ?? null) === $id) {
                    $invitation['status'] = $status;
                    $invitation['acceptedVia'] = $status === 'accepted' ? 'manual' : null;

                    return $invitation;
                }
            }
        }

        return $this->makeInvitation($id, 'unknown@test.local', 'r_member', $status);
    }

    /**
     * Wrap rows in the cursor-pagination envelope the API emits.
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{status:int, body:array<string,mixed>}
     */
    private function paginate(array $rows): array
    {
        return ['status' => 200, 'body' => [
            'data' => $rows,
            'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => count($rows)],
        ]];
    }
}
