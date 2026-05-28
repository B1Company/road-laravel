<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
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
        'api.road.test/iam/identity/me' => Http::response([
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
        'api.road.test/me/business-units' => Http::response([
            'data' => [
                'memberships' => [
                    [
                        'businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'],
                        'status' => 'active',
                        'joinedAt' => '2024-01-01T00:00:00Z',
                        'roles' => [['id' => 'r_1', 'name' => 'Owner']],
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
        'api.road.test/organization/business-units/bu_1' => Http::response([
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
