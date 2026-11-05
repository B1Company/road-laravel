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

    /**
     * Road's own definitions, by the root that owns them, as `roadPermissions()`
     * in the API's `authz/seed/road-definitions.ts` writes them. The scope tree
     * reads these by reference: a BU's catalog is `business_units` and nothing
     * else, so `read:BusinessUnit`, declared at `system`, is unknown at a BU.
     * `RoadCatalogDriftTest` holds this list to those sources.
     */
    private const ROAD_CATALOG = [
        'system' => [
            'create:BusinessUnit', 'read:BusinessUnit', 'manage:System', 'manage:AdminUser',
            'create:Platform', 'read:Platform', 'update:Platform', 'manage:Platform',
            'manage:PlatformIdentity', 'update:PlatformOperations', 'manage:PlatformHomologation',
            'create:PlatformContract', 'read:PlatformContract', 'update:PlatformContract',
            'manage:CrossPlatformGrant', 'read:CrossPlatformAudit',
            'read:Scope', 'read:Permission', 'manage:Permission', 'read:Role', 'manage:Role', 'manage:Assignment',
        ],
        'business_units' => [
            'read:Member', 'manage:Member', 'read:Invitation', 'manage:Invitation',
            'read:Role', 'manage:Role', 'read:Permission', 'manage:Permission',
            'read:BUSettings', 'update:BUSettings', 'read:BUDashboard', 'manage:PlatformPermissions',
            'read:ExtensionInstall', 'manage:ExtensionInstall', 'read:Scope', 'manage:Assignment',
        ],
        'developer_accounts' => [
            'read:DeveloperAccount', 'manage:DeveloperAccount', 'create:Platform',
            'read:Extension', 'create:Extension', 'manage:Extension',
        ],
        'platforms' => [
            'read:Platform', 'update:Platform', 'manage:Platform', 'manage:PlatformIdentity',
            'update:PlatformOperations', 'read:PlatformCredential', 'create:PlatformCredential',
            'update:PlatformCredential', 'delete:PlatformCredential', 'manage:PlatformCredential',
            'read:ExtensionPoint', 'manage:ExtensionPoint',
            'read:Permission', 'manage:Permission', 'read:Role', 'manage:Role',
        ],
    ];

    /** What `CreateAssignmentDto` takes. */
    private const ASSIGNMENT_FIELDS = ['subjectType', 'subjectId', 'roleId', 'expiresAt'];

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

        // ── Control plane: scopes, permissions, roles, assignments ─────────
        $control = $this->handleControlPlane($method, $path, $body ?? [], $query ?? [], $userId);
        if ($control !== null) {
            return $control;
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
                (string) ($body['roleId'] ?? 'role_member'),
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

        // A stranger reads a BU that doesn't exist as one they hold nothing in.
        if ($this->namesNoScope($body)) {
            return ['status' => 200, 'body' => ['data' => ['results' => array_map(
                static fn (mixed $code): array => ['code' => (string) $code, 'allowed' => false, 'reason' => 'denied_no_grant'],
                $codes,
            )]]];
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
        if ($bu === null) {
            return null;
        }

        return ['bu' => $bu, 'platformId' => isset($body['platformId']) ? (string) $body['platformId'] : null];
    }

    /**
     * Whether a short form names a BU or subscription that doesn't exist.
     *
     * @param  array<string,mixed>  $body
     */
    private function namesNoScope(array $body): bool
    {
        $buId = $body['businessUnitId'] ?? null;
        if (! is_string($buId) || $buId === '' || (is_string($body['scopeId'] ?? null) && $body['scopeId'] !== '')) {
            return false;
        }
        if ($this->scenario->findBusinessUnit($buId) === null) {
            return true;
        }

        return isset($body['platformId']) && ! isset($this->scenario->subscriptions[$buId][(string) $body['platformId']]);
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
     * BU, the platform's at a subscription. `catalog: road` asks all of Road's
     * instead. A code outside the catalog is `denied_unknown_code` and a role of
     * another catalog never answers for it, except a BU's `*`, which covers the
     * BU's catalog and every scope below the BU. Codes match case-insensitively.
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

        // Road's catalog declares its own codes and nothing else, whatever a
        // scenario's BU roles list.
        $covered = self::ROAD_CATALOG['business_units'];
        $declared = $roadCatalog ? array_merge(...array_values(self::ROAD_CATALOG)) : $covered;
        if ($scope['platformId'] !== null && ! $roadCatalog) {
            $platformId = $scope['platformId'];
            $declared = array_merge(...array_values(['' => []] + $this->scenario->subscriptions[$buId][$platformId]['roles']));
            $covered = $declared;
            $held = $this->scenario->subscriptionPermissions($subject, $buId, $platformId);
            $hasAll = $hasAll || in_array(Permission::WILDCARD, $held, true);
        }

        $candidates = $this->candidates($declared, $code);
        if ($candidates === []) {
            return $deny('denied_unknown_code');
        }
        if (array_intersect($candidates, array_map('strtolower', $held)) !== []) {
            return ['code' => $code, 'allowed' => true, 'reason' => 'allowed_role'];
        }
        if ($hasAll && $this->candidates($covered, $code) !== []) {
            return ['code' => $code, 'allowed' => true, 'reason' => 'allowed_grants_all'];
        }

        return $deny('denied_no_grant');
    }

    /**
     * The declared codes that satisfy a required one, lower-cased: the same
     * code, or `manage` on the same subject asked for a CRUD action. A role
     * passes only by holding one of these.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    private function candidates(array $declared, string $required): array
    {
        $required = strtolower($required);
        [$action, $noun] = array_pad(explode(':', $required, 2), 2, '');
        $manage = in_array($action, self::MANAGE_IMPLIES, true) ? 'manage:'.$noun : null;

        return array_values(array_filter(
            array_map('strtolower', $declared),
            static fn (string $code): bool => $code === $required || $code === $manage,
        ));
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
            if ($scope['platformId'] === null && ! in_array(strtolower($code), array_map('strtolower', self::ROAD_CATALOG['business_units']), true)) {
                continue;
            }
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
     * `/authz/*` beyond checks. Read paths come from the scenario; writes
     * acknowledge with a plausible echo. A body the API's DTOs would refuse is
     * a 400 here too.
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,scalar|null>  $query
     * @return array{status:int, body:array<string,mixed>}|null
     */
    private function handleControlPlane(string $method, string $path, array $body, array $query, ?string $userId): ?array
    {
        if ($method === 'GET' && $path === '/authz/scopes/lookup') {
            return ['status' => 200, 'body' => ['data' => $this->synthScope('scope_lookup', [
                'type' => (string) ($query['type'] ?? 'business_unit'),
                'externalId' => isset($query['externalId']) ? (string) $query['externalId'] : null,
            ])]];
        }

        if ($method === 'GET' && preg_match('#^/authz/scopes/([^/]+)/roles$#', $path, $m) === 1) {
            return $this->paginate($this->rolesForScope($m[1]));
        }

        if ($method === 'POST' && preg_match('#^/authz/scopes/([^/]+)/roles$#', $path, $m) === 1) {
            $this->refuseFields($body, ['name', 'description', 'permissionIds', 'cloneFromRoleId'], $path);

            return ['status' => 201, 'body' => ['data' => $this->makeRole($body + ['custom' => true, 'scopeId' => $m[1]])]];
        }

        if ($method === 'GET' && preg_match('#^/authz/scopes/([^/]+)/permissions$#', $path, $m) === 1) {
            return $this->paginate($this->permissionsAt($m[1]));
        }

        if ($method === 'GET' && preg_match('#^/authz/scopes/([^/]+)/assignments$#', $path, $m) === 1) {
            return $this->paginate(array_values(array_filter(
                $this->allAssignments(),
                static fn (array $a): bool => $a['scopeId'] === $m[1],
            )));
        }

        if ($method === 'POST' && preg_match('#^/authz/scopes/([^/]+)/assignments$#', $path, $m) === 1) {
            $this->refuseFields($body, self::ASSIGNMENT_FIELDS, $path);

            return ['status' => 201, 'body' => ['data' => $this->makeAssignment($body + ['scopeId' => $m[1]])]];
        }

        if ($method === 'POST' && preg_match('#^/authz/scopes/([^/]+)/assignments/batch$#', $path) === 1) {
            $rows = [];
            foreach ((array) ($body['assignments'] ?? []) as $i => $row) {
                $this->refuseFields((array) $row, self::ASSIGNMENT_FIELDS, $path);
                $rows[] = ['id' => 'asg_new_'.$i, 'created' => true];
            }

            return ['status' => 201, 'body' => ['data' => $rows]];
        }

        if ($method === 'GET' && preg_match('#^/authz/scopes/([^/]+)$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->synthScope($m[1])]];
        }

        if (preg_match('#^/authz/roles/([^/]+)/permissions(?:/([^/]+))?$#', $path, $m) === 1) {
            if ($method === 'GET') {
                $role = $this->readRole($m[1], $query, $userId, $path);

                return $this->paginate($this->permissionRows($role['codes'], $role['ownerScopeId']));
            }

            $role = $this->findRole($m[1]);
            if ($role === null) {
                throw $this->apiError(404, $path, sprintf("Role '%s' was not found.", $m[1]), 'ROLE_NOT_FOUND');
            }

            return ['status' => 200, 'body' => ['data' => $role['wire']]];
        }

        if (preg_match('#^/authz/roles/([^/]+)$#', $path, $m) === 1) {
            if ($method === 'GET') {
                return ['status' => 200, 'body' => ['data' => $this->readRole($m[1], $query, $userId, $path)['wire']]];
            }

            return $this->handleRole($method, $m[1], $body, $path);
        }

        if ($method === 'GET' && preg_match('#^/authz/subjects/([^/]+)/([^/]+)/permissions$#', $path, $m) === 1) {
            return ['status' => 200, 'body' => ['data' => $this->effective((string) ($query['scopeId'] ?? ''), $m[2])]];
        }

        if ($method === 'GET' && preg_match('#^/authz/subjects/([^/]+)/([^/]+)/assignments$#', $path, $m) === 1) {
            return $this->paginate(array_values(array_filter(
                $this->allAssignments(),
                static fn (array $a): bool => $a['subjectType'] === $m[1] && $a['subjectId'] === $m[2],
            )));
        }

        if (preg_match('#^/authz/assignments/([^/]+)$#', $path, $m) === 1) {
            if ($method === 'DELETE') {
                return ['status' => 200, 'body' => ['data' => ['id' => $m[1], 'revoked' => true]]];
            }
            foreach ($this->allAssignments() as $assignment) {
                if ($assignment['id'] === $m[1]) {
                    return ['status' => 200, 'body' => ['data' => $assignment]];
                }
            }
            throw new RoadNotFoundException(sprintf('Assignment %s not in scenario.', $m[1]));
        }

        return null;
    }

    /**
     * `GET /authz/roles/:id`, and its permissions. With no `scopeId` the reader
     * needs `read:Role` at the owner, which is above every scope in a scenario,
     * so it is a 403. With one, `read:Role` there, then a 404 unless that scope
     * offers the role.
     *
     * @param  array<string,scalar|null>  $query
     * @return array{wire:array<string,mixed>, codes:list<string>, ownerScopeId:string, offeredAt:string}
     */
    private function readRole(string $roleId, array $query, ?string $userId, string $path): array
    {
        $notFound = fn () => $this->apiError(404, $path, sprintf("Role '%s' was not found.", $roleId), 'ROLE_NOT_FOUND');
        if ($this->findRole($roleId) === null) {
            throw $notFound();
        }

        $scopeId = (string) ($query['scopeId'] ?? '');
        $decision = $scopeId === '' ? null : $this->decide((string) $userId, $this->locateScope($scopeId), 'read:Role', true);
        if ($decision === null || ! $decision['allowed']) {
            throw $this->apiError(403, $path, 'Permission denied');
        }

        return $this->findRole($roleId, $scopeId) ?? throw $notFound();
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{status:int, body:array<string,mixed>}
     */
    private function handleRole(string $method, string $roleId, array $body, string $path): array
    {
        $role = $this->findRole($roleId);
        if ($role === null) {
            throw $this->apiError(404, $path, sprintf("Role '%s' was not found.", $roleId), 'ROLE_NOT_FOUND');
        }
        if ($method === 'DELETE') {
            return ['status' => 200, 'body' => ['data' => ['assignmentsRevoked' => $role['wire']['assignmentCount']]]];
        }

        return ['status' => 200, 'body' => ['data' => array_merge($role['wire'], $body)]];
    }

    /**
     * The API forbids a field its DTO does not declare, so a caller still
     * sending an old field fails here too.
     *
     * @param  array<array-key,mixed>  $body
     * @param  list<string>  $allowed
     */
    private function refuseFields(array $body, array $allowed, string $path): void
    {
        $extra = array_diff(array_map('strval', array_keys($body)), $allowed);
        if ($extra !== []) {
            throw $this->apiError(400, $path, sprintf('property %s should not exist', implode(', ', $extra)));
        }
    }

    /**
     * Every role the scenario declares, with the scope that offers it. As on
     * the API, the owner sits above that scope: Road owns what a BU offers, and
     * a platform's management scope owns what a subscription offers.
     *
     * @return list<array{wire:array<string,mixed>, codes:list<string>, ownerScopeId:string, offeredAt:string}>
     */
    private function allRoles(): array
    {
        $roles = [];
        foreach ($this->scenario->rolesByBu as $buId => $byName) {
            foreach ($byName as $name => $codes) {
                $roles[] = $this->declaredRole($name, $codes, 'scope_road', (string) $this->scenario->businessUnits[$buId]['iamScopeId']);
            }
        }
        foreach ($this->scenario->subscriptions as $byPlatform) {
            foreach ($byPlatform as $platformId => $sub) {
                foreach ($sub['roles'] as $name => $codes) {
                    $roles[] = $this->declaredRole($name, $codes, 'scope_mgmt_'.$platformId, $sub['scopeId']);
                }
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>  $codes
     * @return array{wire:array<string,mixed>, codes:list<string>, ownerScopeId:string, offeredAt:string}
     */
    private function declaredRole(string $name, array $codes, string $ownerScopeId, string $offeredAt): array
    {
        return [
            'wire' => $this->makeRole([
                'name' => $name,
                'grantsAll' => in_array(Permission::WILDCARD, $codes, true),
                'permissionCount' => count($codes),
                'scopeId' => $ownerScopeId,
            ], 'role_'.$name),
            'codes' => $codes,
            'ownerScopeId' => $ownerScopeId,
            'offeredAt' => $offeredAt,
        ];
    }

    /**
     * A role by id, and when `$offeredAt` is given, only as that scope offers it.
     *
     * @return array{wire:array<string,mixed>, codes:list<string>, ownerScopeId:string, offeredAt:string}|null
     */
    private function findRole(string $roleId, ?string $offeredAt = null): ?array
    {
        foreach ($this->allRoles() as $role) {
            if ($role['wire']['id'] === $roleId && ($offeredAt === null || $role['offeredAt'] === $offeredAt)) {
                return $role;
            }
        }

        return null;
    }

    /** @return list<array<string,mixed>> */
    private function rolesForScope(string $scopeId): array
    {
        $roles = [];
        foreach ($this->allRoles() as $role) {
            if ($role['offeredAt'] === $scopeId) {
                $roles[] = $role['wire'];
            }
        }

        return $roles;
    }

    /**
     * The codes the roles at a scope compose, as permission rows.
     *
     * @return list<array<string,mixed>>
     */
    private function permissionsAt(string $scopeId): array
    {
        $codes = [];
        foreach ($this->allRoles() as $role) {
            if ($role['offeredAt'] === $scopeId) {
                $codes = array_merge($codes, $role['codes']);
            }
        }

        return $this->permissionRows(array_values(array_unique($codes)), $scopeId);
    }

    /**
     * @param  array<mixed>  $codes
     * @return list<array<string,mixed>>
     */
    private function permissionRows(array $codes, string $scopeId): array
    {
        $rows = [];
        foreach ($codes as $code) {
            if (is_string($code) && $code !== Permission::WILDCARD) {
                $rows[] = [
                    'id' => 'perm_'.str_replace(':', '_', $code),
                    'code' => $code,
                    'name' => $code,
                    'description' => null,
                    'category' => null,
                    'audience' => 'customer',
                    'scopeId' => $scopeId,
                    'createdAt' => '2024-01-01T00:00:00Z',
                    'updatedAt' => '2024-01-01T00:00:00Z',
                ];
            }
        }

        return $rows;
    }

    /**
     * Every role the scenario's users hold, at the scope they hold it.
     *
     * @return list<array<string,mixed>>
     */
    private function allAssignments(): array
    {
        $rows = [];
        foreach (array_keys($this->scenario->users) as $userId) {
            foreach ($this->scenario->memberships((string) $userId)['memberships'] as $membership) {
                $bu = $this->scenario->businessUnits[(string) ($membership['businessUnit']['id'] ?? '')] ?? null;
                foreach ($membership['roles'] ?? [] as $role) {
                    $rows[] = $this->heldAssignment((string) $userId, (string) $role['name'], (string) ($bu['iamScopeId'] ?? ''));
                }
                foreach ($membership['platformSubscriptions'] ?? [] as $sub) {
                    foreach ($sub['roles'] ?? [] as $role) {
                        $rows[] = $this->heldAssignment((string) $userId, (string) $role['name'], (string) $sub['scopeId']);
                    }
                }
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function heldAssignment(string $userId, string $roleName, string $scopeId): array
    {
        return ['id' => 'asg_'.$userId.'_'.$roleName] + $this->makeAssignment([
            'subjectId' => $userId,
            'roleId' => 'role_'.$roleName,
            'roleName' => $roleName,
            'scopeId' => $scopeId,
        ]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function makeRole(array $input, ?string $id = null): array
    {
        $name = (string) ($input['name'] ?? '');

        return [
            'id' => $id ?? ('role_'.($name !== '' ? $name : 'new')),
            'name' => $name,
            'description' => $input['description'] ?? null,
            'audience' => 'customer',
            'grantsAll' => (bool) ($input['grantsAll'] ?? false),
            'custom' => (bool) ($input['custom'] ?? false),
            'scopeId' => (string) ($input['scopeId'] ?? ''),
            'permissionCount' => (int) ($input['permissionCount'] ?? count((array) ($input['permissionIds'] ?? []))),
            'assignmentCount' => (int) ($input['assignmentCount'] ?? 0),
            'createdAt' => '2024-01-01T00:00:00Z',
            'updatedAt' => '2024-01-01T00:00:00Z',
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
            'catalogScopeId' => 'scope_road',
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

        return $this->makeInvitation($id, 'unknown@test.local', 'role_member', $status);
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
