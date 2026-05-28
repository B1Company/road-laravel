<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

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

    public function __construct(public readonly RoadScenario $scenario)
    {
    }

    /**
     * @param  array<string,mixed>|null  $body
     * @param  string|null  $userId  Hint of which user is calling.
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $method, string $path, ?array $body = null, ?string $userId = null): array
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
                    'memberships'        => $memberships,
                    'pendingInvitations' => [],
                ],
            ]];
        }

        if ($method === 'GET' && $path === '/me/permissions') {
            return ['status' => 200, 'body' => ['data' => ['byBusinessUnit' => []]]];
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
        $debug = (bool) ($body['debug'] ?? false);

        $verdict = $this->verdict($subjectId, $scopeId, $required);
        $bu = $this->resolveBuFromScope($scopeId);
        $rolesOnBu = $bu === null
            ? []
            : $this->rolesUserHasOnBu($subjectId, $bu['id']);

        $reason = $verdict
            ? sprintf('granted: %s held by roles [%s]', $required, implode(', ', $rolesOnBu))
            : sprintf('no role grants %s', $required);

        $data = [
            'allowed'         => $verdict,
            'reason'          => $reason,
            'evaluatedScopes' => $scopeId !== '' ? [$scopeId] : [],
        ];

        if ($debug) {
            $data['decision'] = [
                'subject'         => 'user:'.$subjectId,
                'scope'           => $scopeId,
                'required'        => [$required],
                'grants'          => array_map(
                    fn (string $role) => [
                        'via'         => "role:$role",
                        'permissions' => $this->scenario->rolesByBu[$bu['id'] ?? ''][$role] ?? [],
                    ],
                    $rolesOnBu,
                ),
                'verdict'         => $verdict ? 'allow' : 'deny',
                'reason'          => $reason,
                'evaluatedScopes' => $scopeId !== '' ? [$scopeId] : [],
            ];
        }

        // The real /authorize endpoint always 200s with { allowed: bool }
        // — `Road::assert()` is what turns a deny into a RoadAuthzException.
        return ['status' => 200, 'body' => ['data' => $data]];
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
                'allowed'    => $this->verdict($subjectId, $scopeId, $perm),
            ];
        }

        return ['status' => 200, 'body' => ['data' => ['results' => $results]]];
    }

    private function verdict(string $subjectId, string $scopeId, string $required): bool
    {
        $bu = $this->resolveBuFromScope($scopeId);
        if ($bu === null || $subjectId === '' || $required === '') {
            return false;
        }

        $effective = $this->scenario->effectivePermissions($subjectId, $bu['id']);

        if (in_array(\B1Road\Laravel\Authorization\Permission::WILDCARD, $effective, true)) {
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
     * Scenarios identify BUs by their human id (`bu_1`), but the real
     * `/authorize` engine takes the IAM scope id (`scope_bu_1`). Accept
     * either — when an integrator uses the BU id directly as scopeId,
     * resolve it; otherwise look up by iamScopeId.
     *
     * @return array<string,mixed>|null
     */
    private function resolveBuFromScope(string $scopeId): ?array
    {
        if ($scopeId === '') {
            return null;
        }

        $direct = $this->scenario->findBusinessUnit($scopeId);
        if ($direct !== null) {
            return $direct;
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
