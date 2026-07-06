<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\Membership;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

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

it('calls /iam/identity/me and decodes into CurrentUser', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/iam/identity/me' => Http::response([
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
        return $req->hasHeader('Authorization', 'Bearer test-access-token')
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

it('permissions() resolves BU->scope, calls the scoped endpoint, and keys by BU id', function () {
    seedAuthedContext();

    Http::fake([
        'api.road.test/api/alpha/me/business-units' => Http::response([
            'data' => [
                'memberships' => [
                    ['businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'], 'status' => 'active', 'joinedAt' => '', 'roles' => [], 'platformSubscriptions' => []],
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
        'api.road.test/api/alpha/iam/authorization/me/permissions*' => Http::response([
            'data' => [
                'scope_1' => [
                    ['action' => 'manage', 'subject' => 'Project'],
                    ['action' => '*', 'subject' => '*'],
                ],
            ],
        ], 200),
    ]);

    $perms = app(RoadClient::class)->me()->permissions();

    expect($perms->byBusinessUnit)->toBe([
        'bu_1' => ['manage:Project', '*'],
    ]);

    // Hits the scoped authorization endpoint with the resolved scope id.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/api/alpha/iam/authorization/me/permissions')
        && str_contains($req->url(), 'scopes=scope_1'));
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
        'api.road.test/api/alpha/iam/authorization/scopes/scope_plat_1/roles*' => Http::response([
            'data' => [
                ['id' => 'role_admin', 'name' => 'Platform Admin', 'description' => null, 'permissions' => ['manage:Invoice'], 'isSystem' => false, 'assignmentCount' => 1, 'createdAt' => '2024-01-01T00:00:00Z'],
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
