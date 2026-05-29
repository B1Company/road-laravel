<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Authorization\Permission;
use B1Road\Laravel\Exceptions\RoadNotFoundException;

/**
 * In-memory router for fake Road API calls. Port of
 * `apps/sdks/road-nestjs/src/testing/in-memory-backend.ts`.
 *
 * Matches `method + path` against a small set of handlers and serves
 * data from the test scenario. Implements the same `/iam/authorization/authorize`
 * verdict logic Road's real engine uses for the basic case — manage:X
 * expands to CRUD, `*` is wildcard.
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
                'data' => [
                    'memberships' => $memberships,
                    'pendingInvitations' => [],
                ],
            ]];
        }

        // Effective permissions — scope-parameterized, returning (action,
        // subject) TUPLES keyed by scope id. Mirrors the real
        // `GET /iam/authorization/me/permissions?scopes=<csv>`. Only answers
        // for the requested scopes, so a test catches an SDK that sends a
        // missing/wrong scopes list instead of silently passing.
        if ($method === 'GET' && $path === '/iam/authorization/me/permissions') {
            return $this->handleMyPermissions($query ?? [], $userId);
        }

        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)$#', $path, $m) === 1) {
            $bu = $this->scenario->findBusinessUnit($m[1]);
            if ($bu === null) {
                throw new RoadNotFoundException(sprintf('Business unit %s not in scenario.', $m[1]));
            }

            return ['status' => 200, 'body' => ['data' => $bu]];
        }

        if ($method === 'POST' && $path === '/iam/authorization/authorize') {
            return $this->handleAuthorize($body ?? []);
        }

        if ($method === 'POST' && $path === '/iam/authorization/authorize/batch') {
            return $this->handleAuthorizeBatch($body ?? []);
        }

        throw new RoadNotFoundException(sprintf('No fake handler for %s %s.', $method, $path));
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleAuthorize(array $body): array
    {
        $subjectId = (string) ($body['subjectId'] ?? '');
        $scopeId = (string) ($body['scopeId'] ?? '');
        $required = (string) ($body['permission'] ?? '');

        $verdict = $this->verdict($subjectId, $scopeId, $required);
        $bu = $this->resolveBuFromScope($scopeId);
        $rolesOnBu = $bu === null
            ? []
            : $this->rolesUserHasOnBu($subjectId, $bu['id']);

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
    private function handleAuthorizeBatch(array $body): array
    {
        $subjectId = (string) ($body['subjectId'] ?? '');
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
     * declared in the scenario (manage→CRUD expansion is the API's job and
     * is exercised API-side, not here).
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

        $effective = $this->scenario->effectivePermissions($subjectId, $bu['id']);

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
}
