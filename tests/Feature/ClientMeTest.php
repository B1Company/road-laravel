<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\Membership;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(ActsAsRoadUser::class);

function seedAuthedContext(): void
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(
        id: 'u_1',
        email: 'u1@example.com',
        name: 'User 1',
    ));
    $ctx->setToken('test-access-token');
    $ctx->setRequestId('req_test_1');
}

// `/me/profile` is the API's one "who am I" route. `/iam/identity/me` was
// removed from the API, and this call kept asking it: a 404 in production.
it('calls /me/profile and decodes into CurrentUser', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/me/profile' => Http::response([
            'data' => [
                'id' => 'u_1',
                'name' => 'User 1',
                'email' => 'u1@example.com',
                'avatarUrl' => 'https://cdn.test/u1.png',
            ],
        ], 200),
    ]);

    $me = app(RoadClient::class)->me()->get();

    expect($me->id)->toBe('u_1');
    expect($me->email)->toBe('u1@example.com');
    expect($me->avatarUrl)->toBe('https://cdn.test/u1.png');

    Http::assertSent(function (HttpRequest $req) {
        return $req->url() === 'https://api.road.test/api/alpha/me/profile'
            && $req->hasHeader('Authorization', 'Bearer test-access-token')
            && $req->hasHeader('X-Request-Id', 'req_test_1');
    });
});

it('decodes /me/business-units into MyBusinessUnits', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/me/business-units' => Http::response([
            'data' => [
                'memberships' => [
                    [
                        'businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'],
                        'status' => 'active',
                        'joinedAt' => '2024-01-01T00:00:00Z',
                        'roles' => [['id' => 'r_1', 'name' => 'Owner']],
                        'platformSubscriptions' => [],
                    ],
                ],
                'pendingInvitations' => [],
            ],
        ], 200),
    ]);

    $result = app(RoadClient::class)->me()->businessUnits();

    expect($result->memberships)->toHaveCount(1);
    expect($result->memberships[0]->businessUnit->name)->toBe('B1');
});

it('returns a BusinessUnitDetail via Road::client()->businessUnits($buId)->fetch()', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response([
            'data' => [
                'id' => 'bu_1',
                'name' => 'B1',
                'slug' => 'b1',
                'status' => 'active',
                'memberCount' => 3,
                'memberLimit' => 50,
                'joinCode' => null,
                'createdAt' => '2024-01-01T00:00:00Z',
                'iamScopeId' => 'scope_1',
            ],
        ], 200),
    ]);

    /** @var RoadClient $client */
    $client = app(RoadClient::class);
    $bu = $client->businessUnits()->__invoke('bu_1')->fetch();

    expect($bu->id)->toBe('bu_1');
    expect($bu->iamScopeId)->toBe('scope_1');
});

it('maps 404 to RoadNotFoundException', function () {
    seedAuthedContext();

    Http::fake([
        '*' => Http::response(['error' => ['code' => 'not_found', 'message' => 'No such BU']], 404),
    ]);

    expect(fn () => app(RoadClient::class)->businessUnits()->get('does-not-exist'))
        ->toThrow(RoadNotFoundException::class);
});

it('throws RoadAuthnException when called outside a road-protected context', function () {
    Http::fake();

    expect(fn () => app(RoadClient::class)->me()->get())
        ->toThrow(RoadAuthnException::class);
});

it('permissions() reads every BU and subscription scope and keys by BU id', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/me/business-units' => Http::response([
            'data' => [
                'memberships' => [
                    ['businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'], 'status' => 'active', 'joinedAt' => '', 'roles' => [], 'platformSubscriptions' => [
                        ['platformId' => 'plat_1', 'slug' => 'one', 'scopeId' => 'scope_sub', 'roles' => []],
                    ]],
                ],
                'pendingInvitations' => [],
            ],
        ], 200),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response([
            'data' => [
                'id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1', 'status' => 'active',
                'memberCount' => 1, 'memberLimit' => null, 'joinCode' => null,
                'createdAt' => '', 'iamScopeId' => 'scope_1',
            ],
        ], 200),
        'api.road.test/api/alpha/authz/me/permissions*' => Http::response([
            'data' => [
                ['scopeId' => 'scope_1', 'all' => false, 'codes' => ['manage:Member', 'read:Member']],
                ['scopeId' => 'scope_sub', 'all' => true, 'codes' => []],
            ],
        ], 200),
    ]);
    Http::preventStrayRequests();

    $perms = app(RoadClient::class)->me()->permissions();

    expect($perms->byBusinessUnit)->toBe([
        'bu_1' => ['manage:Member', 'read:Member'],
    ]);
    // The subscription's scope comes back too, and `all` reads as `*`.
    expect($perms->byScope)->toBe([
        'scope_1' => ['manage:Member', 'read:Member'],
        'scope_sub' => ['*'],
    ]);

    // One read of both scopes.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/api/alpha/authz/me/permissions')
        && $req->data() === ['scopes' => 'scope_1,scope_sub']);
    // The non-existent bare `/me/permissions` must never be called.
    Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), 'alpha/me/permissions'));
});

it('exposes memberships() as a convenience over businessUnits() (C1/parity)', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/me/business-units' => Http::response([
            'data' => [
                'memberships' => [[
                    'businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'],
                    'status' => 'active',
                    'joinedAt' => '2024-01-01T00:00:00Z',
                    'roles' => [['id' => 'r_1', 'name' => 'Owner']],
                    'platformSubscriptions' => [],
                ]],
                'pendingInvitations' => [],
            ],
        ], 200),
    ]);

    $memberships = app(RoadClient::class)->me()->memberships();

    expect($memberships)->toHaveCount(1);
    expect($memberships[0])->toBeInstanceOf(Membership::class);
    expect($memberships[0]->businessUnit->id)->toBe('bu_1');
});

it('lists platform roles by resolving the subscription then its scope roles (parity)', function () {
    seedAuthedContext();

    Http::fake([
        // Hop 1: resolve (platformId, businessUnitId) → the subscription's scope.
        'api.road.test/api/alpha/organization/business-units/bu_1/subscriptions/plat_gw' => Http::response([
            'data' => ['subscriptionId' => 'sub_1', 'platformId' => 'plat_gw', 'slug' => 'payment-gateway', 'scopeId' => 'scope_plat_1'],
        ], 200),
        // Hop 2: list that scope's roles.
        'api.road.test/api/alpha/authz/scopes/scope_plat_1/roles*' => Http::response([
            'data' => [
                ['id' => 'role_admin', 'name' => 'Platform Admin', 'description' => null, 'audience' => 'customer', 'grantsAll' => false, 'custom' => false, 'scopeId' => 'scope_mgmt', 'permissionCount' => 1, 'assignmentCount' => 1, 'createdAt' => '2024-01-01T00:00:00Z', 'updatedAt' => '2024-01-01T00:00:00Z'],
            ],
            'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1],
        ], 200),
    ]);

    $roles = app(RoadClient::class)->me()->platformRoles('plat_gw', 'bu_1');

    // Assert *through* both hops — the scopeId from hop 1 drove hop 2, and real
    // Role objects came back.
    expect($roles)->toHaveCount(1);
    expect($roles[0])->toBeInstanceOf(Role::class);
    expect($roles[0]->name)->toBe('Platform Admin');
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/subscriptions/plat_gw'));
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/scopes/scope_plat_1/roles'));
});

it('permissions() splits more scopes than the API takes at once', function () {
    seedAuthedContext();

    $subscriptions = array_map(
        fn (int $i) => ['platformId' => 'plat_'.$i, 'slug' => 'p'.$i, 'scopeId' => 'scope_sub_'.$i, 'roles' => []],
        range(1, 50),
    );
    Http::fake([
        'api.road.test/api/alpha/me/business-units' => Http::response(['data' => [
            'memberships' => [['businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'], 'status' => 'active', 'joinedAt' => '', 'roles' => [], 'platformSubscriptions' => $subscriptions]],
            'pendingInvitations' => [],
        ]]),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1', 'status' => 'active', 'memberCount' => 1, 'memberLimit' => null, 'joinCode' => null, 'createdAt' => '', 'iamScopeId' => 'scope_1']]),
        'api.road.test/api/alpha/authz/me/permissions*' => Http::response(['data' => []]),
    ]);
    Http::preventStrayRequests();

    app(RoadClient::class)->me()->permissions();

    $sizes = Http::recorded()
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/authz/me/permissions'))
        ->map(fn (array $pair) => count(explode(',', $pair[0]->data()['scopes'])))
        ->values()
        ->all();
    expect($sizes)->toBe([50, 1]);
});

it('permissions() serves a platform role through the fake, under its subscription scope', function () {
    Road::fake(
        RoadScenario::make()
            ->withUser('u_1')
            ->withBusinessUnit('bu_1')
            ->withRole('bu_1', 'Reader', permissions: ['read:Member'])
            ->withMember('bu_1', 'u_1', roles: ['Reader'])
            ->withSubscription('bu_1', 'plat_acme', scopeId: 'scope_sub', roles: ['Admin' => ['manage:Invoice']], members: ['u_1' => ['Admin']]),
    );
    $this->actingAsRoadUser('u_1');

    $perms = Road::client()->me()->permissions();

    expect($perms->byBusinessUnit)->toBe(['bu_1' => ['read:Member']]);
    expect($perms->byScope['scope_sub'])->toBe(['manage:Invoice', 'create:Invoice', 'read:Invoice', 'update:Invoice', 'delete:Invoice']);
});
