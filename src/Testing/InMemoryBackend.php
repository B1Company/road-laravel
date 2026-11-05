<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Authorization\Permission;
use B1Road\Laravel\Client\ErrorMapper;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;

/**
 * In-memory router for fake Road API calls. Port of
 * `apps/sdks/road-nestjs/src/testing/in-memory-backend.ts`.
 *
 * Matches `method + path` against a set of handlers and serves data from the
 * test scenario. The check logic mirrors Road's engine for the basic case: each
 * scope has one catalog, `manage:X` covers CRUD, `*` holds them all. Read paths
 * (members, roles, invitations, effective permissions) are served from the
 * scenario; mutations acknowledge with a plausible echo so an integrator's
 * code under test runs without the network.
 */
final class InMemoryBackend
{
    /** What `manage:X` implies, as the engine expands it. */
    private const MANAGE_IMPLIES = ['create', 'read', 'update', 'delete'];

    /** @var list<array{method:string, path:string, body:?array<string,mixed>}> */
    public array $calls = [];

    /**
     * Each invitation's live link token and status, keyed by invitation id
     * (B1-761). Private on purpose: the rows the fake serves never carry a
     * token, as the API never returns one.
     *
     * @var array<string, array{token:string, status:string}>
     */
    private array $inviteLinks = [];

    /**
     * Invitations created through the fake, keyed by id, so they can be
     * accepted like declared ones.
     *
     * @var array<string, array<string,mixed>>
     */
    private array $createdInvitations = [];

    public function __construct(public readonly RoadScenario $scenario)
    {
        foreach ($scenario->invitationsByBu as $list) {
            foreach ($list as $invitation) {
                $id = (string) $invitation['id'];
                $this->inviteLinks[$id] = [
                    'token' => $scenario->invitationTokens[$id] ?? 'fake-invite-token-'.$id,
                    'status' => (string) $invitation['status'],
                ];
            }
        }
    }

    /**
     * The token the invitation's latest email link carries. Test-only: read
     * it through `RoadFakeAssertions::invitationToken()`.
     *
     * @throws \InvalidArgumentException when no invitation has that id.
     */
    public function invitationToken(string $invitationId): string
    {
        if (! isset($this->inviteLinks[$invitationId])) {
            throw new \InvalidArgumentException(sprintf(
                'No invitation %s in the fake. Declare it with RoadScenario::withInvitation() or create it through the client first.',
                $invitationId,
            ));
        }

        return $this->inviteLinks[$invitationId]['token'];
    }

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
        if ($method === 'GET' && $path === '/me/profile') {
            $user = $userId !== null ? $this->scenario->findUser($userId) : null;
            if ($user === null) {
                throw new RoadNotFoundException('No active user in scenario.');
            }

            return ['status' => 200, 'body' => ['data' => $user]];
        }

        // Plan 69: whether the caller's session was opened by Eduzz support.
        if ($method === 'GET' && $path === '/me/session') {
            if ($userId === null || $this->scenario->findUser($userId) === null) {
                throw new RoadNotFoundException('No active user in scenario.');
            }
            // Like Road: a session that is not the caller's is a 404. A test
            // session's id is `sid-<userId>` (ActsAsRoadUser), so a wrong
            // `sid` passed along is caught here instead of answering anyway.
            $sessionId = $query['sessionId'] ?? null;
            if ($sessionId !== null && $sessionId !== 'sid-'.$userId) {
                throw new RoadNotFoundException('No sign-in session with this id belongs to you.');
            }

            return ['status' => 200, 'body' => ['data' => [
                'supportSession' => $this->scenario->isSupportSession($userId),
            ]]];
        }

        if ($method === 'GET' && $path === '/me/business-units') {
            $memberships = $userId !== null ? $this->scenario->memberships($userId)['memberships'] : [];

            return ['status' => 200, 'body' => [
                'data' => ['memberships' => $memberships, 'pendingInvitations' => []],
            ]];
        }

        if ($method === 'GET' && $path === '/me/eduzz/products') {
            // The scenario has no Eduzz-product concept; return an empty page so
            // `Road::fake()` users can call eduzzProducts() without a live token.
            return ['status' => 200, 'body' => [
                'data' => [],
                'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 0],
            ]];
        }

        if ($method === 'GET' && $path === '/authz/me/permissions') {
            return $this->handleMyPermissions($query ?? [], $userId);
        }

        // ── Authorization checks ───────────────────────────────────────────
        if ($method === 'POST' && $path === '/authz/checks') {
            return $this->handleCheck($body ?? [], $userId);
        }

        if ($method === 'GET' && $path === '/authz/checks/explain') {
            return $this->handleExplain($query ?? [], $userId);
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
            $sub = $this->scenario->subscriptions[$m[1]][$m[2]] ?? null;

            return ['status' => 200, 'body' => ['data' => [
                'subscriptionId' => 'sub_'.$m[2],
                'platformId' => $m[2],
                'slug' => $sub['slug'] ?? $m[2],
                'scopeId' => $sub['scopeId'] ?? 'scope_'.$m[2],
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
            return $this->paginate(array_map(
                fn (array $invitation): array => $this->withLiveStatus($invitation),
                $this->scenario->invitationsByBu[$m[1]] ?? [],
            ));
        }

        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/invitations$#', $path) === 1) {
            $id = 'inv_new'.($this->createdInvitations === [] ? '' : '_'.(count($this->createdInvitations) + 1));
            $invitation = $this->makeInvitation(
                $id,
                (string) ($body['email'] ?? ''),
                (string) ($body['roleId'] ?? 'r_member'),
                'pending',
            );
            $this->createdInvitations[$id] = $invitation;
            $this->inviteLinks[$id] = ['token' => $this->mintToken($id), 'status' => 'pending'];

            return ['status' => 201, 'body' => ['data' => $invitation]];
        }

        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/invitations/([^/]+)/cancel$#', $path, $m) === 1) {
            $this->closeLink($m[2], 'cancelled');

            return ['status' => 200, 'body' => ['data' => $this->transitionInvitation($m[2], 'cancelled')]];
        }

        if ($method === 'POST' && preg_match('#^/organization/business-units/([^/]+)/invitations/([^/]+)/resend$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->resendInvitation($path, $m[2])]];
        }

        if ($method === 'POST' && preg_match('#^/organization/invitations/([^/]+)/accept$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->acceptInvitation($path, $m[1], $body ?? [])]];
        }

        if ($method === 'POST' && preg_match('#^/organization/invitations/([^/]+)/reject$#', $path, $m) === 1) {
            $this->closeLink($m[1], 'rejected');

            return ['status' => 200, 'body' => ['data' => $this->transitionInvitation($m[1], 'rejected')]];
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
     * `POST /authz/checks`. The caller is the token-derived user, unless the
     * body names a subject. Answers like the API: a business unit with no such
     * subscription is a 404, an unknown scope id a `denied_unknown_scope`.
     *
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleCheck(array $body, ?string $userId): array
    {
        $codes = $body['codes'] ?? null;
        if (! is_array($codes) || $codes === [] || count($codes) > 100) {
            throw $this->apiError(400, '/authz/checks', 'Send between 1 and 100 codes.');
        }

        $subject = (string) ($body['subjectId'] ?? $userId ?? '');
        $scope = $this->scopeOfCheck($body);
        $roadCatalog = ($body['catalog'] ?? null) === 'road';

        $results = [];
        foreach ($codes as $code) {
            $results[] = $this->decide($subject, $scope, (string) $code, $roadCatalog);
        }

        return ['status' => 200, 'body' => ['data' => ['results' => $results]]];
    }

    /**
     * `GET /authz/checks/explain`: takes a scope id and one code, and nothing
     * shorter, so an SDK that sends a business unit here fails its test.
     *
     * @param  array<string,scalar|null>  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleExplain(array $query, ?string $userId): array
    {
        $scopeId = (string) ($query['scopeId'] ?? '');
        $code = (string) ($query['code'] ?? '');
        if ($scopeId === '' || $code === '') {
            throw $this->apiError(400, '/authz/checks/explain', 'Send one scopeId and one code.');
        }

        $subject = (string) ($query['subjectId'] ?? $userId ?? '');
        $scope = $this->locateScope($scopeId);
        $chain = $scope === null ? [] : array_values(array_unique([$scopeId, (string) $scope['bu']['iamScopeId']]));

        $held = [];
        if ($scope !== null) {
            $bu = (string) $scope['bu']['id'];
            foreach ($this->rolesUserHasOnBu($subject, $bu) as $role) {
                $held[] = $this->makeAssignment(['subjectId' => $subject, 'roleId' => 'role_'.$role, 'roleName' => $role, 'scopeId' => $scope['bu']['iamScopeId']]);
            }
        }

        return ['status' => 200, 'body' => ['data' => $this->decide($subject, $scope, $code, false) + [
            'chain' => $chain,
            'heldRoles' => $held,
        ]]];
    }

    /**
     * `GET /authz/me/permissions`: a list of `{ scopeId, all, codes }`, for the
     * scopes asked or, with no `scopes`, for every scope the caller reaches.
     *
     * @param  array<string,scalar|null>  $query
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleMyPermissions(array $query, ?string $userId): array
    {
        if (! array_key_exists('scopes', $query)) {
            return ['status' => 200, 'body' => ['data' => array_map(
                fn (string $scopeId): array => $this->effective($scopeId, (string) $userId),
                $this->scopesReachedBy((string) $userId),
            )]];
        }

        $requested = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $query['scopes'])),
            static fn (string $s): bool => $s !== '',
        )));
        if ($requested === [] || count($requested) > 50) {
            throw $this->apiError(400, '/authz/me/permissions', 'Send between 1 and 50 scopes.');
        }

        return ['status' => 200, 'body' => ['data' => array_map(
            fn (string $scopeId): array => $this->effective($scopeId, (string) $userId),
            $requested,
        )]];
    }

    /**
     * The scope a check names: a `scopeId`, or the two shorter forms. A
     * `scopeId` that matches nothing is `null`, which the engine answers with
     * `denied_unknown_scope`.
     *
     * @param  array<string,mixed>  $body
     * @return array{bu:array<string,mixed>, platformId:?string}|null
     */
    private function scopeOfCheck(array $body): ?array
    {
        if (is_string($body['scopeId'] ?? null) && $body['scopeId'] !== '') {
            return $this->locateScope($body['scopeId']);
        }

        $buId = $body['businessUnitId'] ?? null;
        if (! is_string($buId) || $buId === '') {
            throw $this->apiError(400, '/authz/checks', 'Send scopeId or businessUnitId.');
        }

        $bu = $this->scenario->findBusinessUnit($buId);
        $platformId = isset($body['platformId']) ? (string) $body['platformId'] : null;
        if ($bu === null || ($platformId !== null && ! isset($this->scenario->subscriptions[$buId][$platformId]))) {
            throw $this->apiError(404, '/authz/checks', 'No scope for that business unit and platform.', 'SCOPE_NOT_FOUND');
        }

        return ['bu' => $bu, 'platformId' => $platformId];
    }

    /**
     * Find a scope by id: a BU's, or a subscription's.
     *
     * @return array{bu:array<string,mixed>, platformId:?string}|null
     */
    private function locateScope(string $scopeId): ?array
    {
        $bu = $this->resolveBuFromScope($scopeId);
        if ($bu !== null) {
            return ['bu' => $bu, 'platformId' => null];
        }

        foreach ($this->scenario->subscriptions as $buId => $byPlatform) {
            foreach ($byPlatform as $platformId => $sub) {
                if ($sub['scopeId'] === $scopeId) {
                    return ['bu' => (array) $this->scenario->findBusinessUnit($buId), 'platformId' => $platformId];
                }
            }
        }

        return null;
    }

    /**
     * The engine's verdict for one code. A scope has one catalog: Road's at a
     * BU, the platform's at a subscription. A code outside it is
     * `denied_unknown_code` and a role of another catalog never answers for it,
     * except a BU's `*`, which covers every scope below the BU.
     *
     * @param  array{bu:array<string,mixed>, platformId:?string}|null  $scope
     * @return array{code:string, allowed:bool, reason:string}
     */
    private function decide(string $subject, ?array $scope, string $code, bool $roadCatalog): array
    {
        $deny = static fn (string $reason): array => ['code' => $code, 'allowed' => false, 'reason' => $reason];
        if ($scope === null) {
            return $deny('denied_unknown_scope');
        }

        $buId = (string) $scope['bu']['id'];
        $held = $this->scenario->effectivePermissions($subject, $buId);
        $hasAll = in_array(Permission::WILDCARD, $held, true);

        if ($scope['platformId'] !== null && ! $roadCatalog) {
            $platformId = $scope['platformId'];
            $declared = array_merge(...array_values(['' => []] + $this->scenario->subscriptions[$buId][$platformId]['roles']));
            $held = $this->scenario->subscriptionPermissions($subject, $buId, $platformId);
            $hasAll = $hasAll || in_array(Permission::WILDCARD, $held, true);
        } else {
            $declared = array_merge(...array_values(['' => []] + ($this->scenario->rolesByBu[$buId] ?? [])));
            if (Permission::isRoadCode($code)) {
                $declared[] = $code;
            }
        }

        if (! $this->satisfiedBy($declared, $code)) {
            return $deny('denied_unknown_code');
        }
        if ($this->satisfiedBy($held, $code)) {
            return ['code' => $code, 'allowed' => true, 'reason' => 'allowed_role'];
        }
        if ($hasAll) {
            return ['code' => $code, 'allowed' => true, 'reason' => 'allowed_grants_all'];
        }

        return $deny('denied_no_grant');
    }

    /**
     * A declared code satisfies a required one when it is the same code, or
     * `manage` on the same subject asked for a CRUD action.
     *
     * @param  list<string>  $declared
     */
    private function satisfiedBy(array $declared, string $required): bool
    {
        if (in_array($required, $declared, true)) {
            return true;
        }
        [$action, $noun] = array_pad(explode(':', $required, 2), 2, '');

        return in_array($action, self::MANAGE_IMPLIES, true) && in_array('manage:'.$noun, $declared, true);
    }

    /**
     * What a subject may do at a scope, as the engine reports it: `manage`
     * expanded, and `all` in place of codes for a holder of `*`.
     *
     * @return array{scopeId:string, all:bool, codes:list<string>}
     */
    private function effective(string $scopeId, string $subject): array
    {
        $scope = $this->locateScope($scopeId);
        if ($scope === null || $subject === '') {
            return ['scopeId' => $scopeId, 'all' => false, 'codes' => []];
        }

        $buId = (string) $scope['bu']['id'];
        $bu = $this->scenario->effectivePermissions($subject, $buId);
        $held = $scope['platformId'] === null
            ? $bu
            : $this->scenario->subscriptionPermissions($subject, $buId, $scope['platformId']);
        if (in_array(Permission::WILDCARD, $bu, true) || in_array(Permission::WILDCARD, $held, true)) {
            return ['scopeId' => $scopeId, 'all' => true, 'codes' => []];
        }

        $codes = [];
        foreach ($held as $code) {
            $codes[$code] = true;
            [$action, $noun] = array_pad(explode(':', $code, 2), 2, '');
            if ($action === 'manage') {
                foreach (self::MANAGE_IMPLIES as $implied) {
                    $codes[$implied.':'.$noun] = true;
                }
            }
        }

        return ['scopeId' => $scopeId, 'all' => false, 'codes' => array_keys($codes)];
    }

    /**
     * Every scope a user reaches: their BUs and those BUs' subscriptions.
     *
     * @return list<string>
     */
    private function scopesReachedBy(string $subject): array
    {
        $scopes = [];
        foreach ($this->scenario->memberships($subject)['memberships'] as $membership) {
            $buId = (string) ($membership['businessUnit']['id'] ?? '');
            $scopes[] = (string) ($this->scenario->businessUnits[$buId]['iamScopeId'] ?? '');
            foreach ($this->scenario->subscriptions[$buId] ?? [] as $sub) {
                $scopes[] = $sub['scopeId'];
            }
        }

        return $scopes;
    }

    /**
     * A BU's scope is its `iamScopeId` (`scope_bu_1`), never its id. Match ONLY
     * by `iamScopeId`, so a test catches an SDK that sends a BU id as a
     * `scopeId`: the API has no such scope.
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
            'id' => 'asg_new',
            'subjectType' => (string) ($input['subjectType'] ?? 'user'),
            'subjectId' => (string) ($input['subjectId'] ?? ''),
            'roleId' => (string) ($input['roleId'] ?? ''),
            'roleName' => (string) ($input['roleName'] ?? ''),
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
     * Mirror of `POST /organization/invitations/{id}/accept` (B1-761), in the
     * API's order: body validation (400), unknown id (409
     * `INVITATION_NOT_FOUND`), the link token (403 `INVITATION_LINK_INVALID`),
     * then status (409 `INVALID_STATUS_TRANSITION`, which is what a token that
     * was already used gets). Nothing changes until every check passes. Expiry
     * (410) is not modelled: scenario dates are fixed placeholders in the past.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function acceptInvitation(string $path, string $invitationId, array $body): array
    {
        $token = $body['token'] ?? null;

        // The DTO: @IsString() @IsNotEmpty() @MaxLength(128).
        $violations = [];
        if (! is_string($token)) {
            $violations[] = 'token must be a string';
        }
        if ($token === null || $token === '') {
            $violations[] = 'token should not be empty';
        }
        if (! is_string($token) || strlen($token) > 128) {
            $violations[] = 'token must be shorter than or equal to 128 characters';
        }
        if ($violations !== []) {
            throw $this->apiError(400, $path, implode('; ', $violations), errors: array_map(
                static fn (string $message): array => ['field' => 'token', 'code' => 'INVALID_FORMAT', 'message' => $message],
                $violations,
            ));
        }

        $link = $this->inviteLinks[$invitationId] ?? null;
        if ($link === null) {
            throw $this->apiError(409, $path, 'INVITATION_NOT_FOUND', code: 'INVITATION_NOT_FOUND');
        }
        if (! hash_equals($link['token'], (string) $token)) {
            throw $this->apiError(
                403,
                $path,
                'This link does not match the invitation. Open the link from the most recent invitation email, or ask the business unit to send a new invitation.',
                code: 'INVITATION_LINK_INVALID',
            );
        }
        if ($link['status'] !== 'pending') {
            throw $this->apiError(409, $path, 'INVALID_STATUS_TRANSITION', code: 'INVALID_STATUS_TRANSITION');
        }

        $this->inviteLinks[$invitationId]['status'] = 'accepted';

        return $this->transitionInvitation($invitationId, 'accepted');
    }

    /**
     * Mirror of `POST .../invitations/{id}/resend`: a NEW link token, so the
     * previous email's link stops working.
     *
     * @return array<string,mixed>
     */
    private function resendInvitation(string $path, string $invitationId): array
    {
        $link = $this->inviteLinks[$invitationId] ?? null;
        if ($link === null) {
            throw $this->apiError(409, $path, 'INVITATION_NOT_FOUND', code: 'INVITATION_NOT_FOUND');
        }
        if ($link['status'] !== 'pending') {
            throw $this->apiError(409, $path, 'INVALID_STATUS_TRANSITION', code: 'INVALID_STATUS_TRANSITION');
        }

        $this->inviteLinks[$invitationId]['token'] = $this->mintToken($invitationId);

        return $this->transitionInvitation($invitationId, 'pending');
    }

    /** An obviously fake, unique link token. Never a real secret. */
    private function mintToken(string $invitationId): string
    {
        return 'fake-invite-token-'.$invitationId.'-'.bin2hex(random_bytes(4));
    }

    /** Move a still-pending invitation's link to a closing status. */
    private function closeLink(string $invitationId, string $status): void
    {
        if (($this->inviteLinks[$invitationId]['status'] ?? null) === 'pending') {
            $this->inviteLinks[$invitationId]['status'] = $status;
        }
    }

    /**
     * A declared row with the status its link has reached since, so a listing
     * agrees with what accept, cancel and reject did.
     *
     * @param  array<string,mixed>  $invitation
     * @return array<string,mixed>
     */
    private function withLiveStatus(array $invitation): array
    {
        $status = $this->inviteLinks[(string) $invitation['id']]['status'] ?? null;
        if ($status !== null && $status !== $invitation['status']) {
            $invitation['status'] = $status;
            $invitation['acceptedVia'] = $status === 'accepted' ? 'manual' : null;
        }

        return $invitation;
    }

    /**
     * The exception the real transport raises for this API refusal: the
     * Problem Details body the API writes, mapped by the same ErrorMapper.
     *
     * @param  list<array{field:string, code:string, message:string}>|null  $errors
     */
    private function apiError(int $status, string $path, string $detail, ?string $code = null, ?array $errors = null): RoadException
    {
        $kinds = [
            400 => ['validation-error', 'Validation Error'],
            403 => ['permission-denied', 'Permission Denied'],
            404 => ['not-found', 'Not Found'],
            409 => ['conflict', 'Conflict'],
        ];
        [$type, $title] = $kinds[$status];

        $problem = [
            'type' => 'https://api.plat.eduzz.com/errors/'.$type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => '/api/alpha'.$path,
            'meta' => ['requestId' => null, 'timestamp' => gmdate('Y-m-d\TH:i:s.v\Z')],
        ];
        if ($errors !== null) {
            $problem['errors'] = $errors;
        }
        if ($code !== null) {
            $problem['code'] = $code;
        }

        return ErrorMapper::map($status, $problem);
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

        if (isset($this->createdInvitations[$id])) {
            $invitation = $this->createdInvitations[$id];
            $invitation['status'] = $status;
            $invitation['acceptedVia'] = $status === 'accepted' ? 'manual' : null;

            return $invitation;
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
