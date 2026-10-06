<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * `Road::roadUserId()` — the Road user id, kept with the session (D18, IR-024).
 *
 * `Road::userId()` is the Auth Server `sub`; Bridge, IAM and webhooks speak the
 * Road user id. The SDK reads it from `/me/profile` once per session and keeps
 * it in the TokenSet. With no answer it throws rather than hand back the `sub`.
 */
const ROAD_USER_UUID = '6f1c2a9e-0d4b-4c1e-9a57-3b2f8e7d1c40';
const AUTH_SERVER_SUB = '312004937762963472';

function signInWithRoadUserId(?string $roadUserId = null): TokenStore
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: AUTH_SERVER_SUB, email: 'e@b1.app', name: 'E', payload: ['sub' => AUTH_SERVER_SUB]));
    $ctx->setToken('at-1');

    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    $store->put(new TokenSet(
        accessToken: 'at-1',
        refreshToken: 'rt-1',
        idToken: null,
        expiresAt: time() + 3600,
        userPayload: ['sub' => AUTH_SERVER_SUB],
        roadUserId: $roadUserId,
    ));

    return $store;
}

it('returns the stored Road user id without asking Road', function () {
    signInWithRoadUserId(ROAD_USER_UUID);
    Http::fake();

    expect(Road::roadUserId())->toBe(ROAD_USER_UUID);
    expect(Road::userId())->toBe(AUTH_SERVER_SUB);
    Http::assertNothingSent();
});

it('reads it once for a session that lacks it, and keeps it', function () {
    $store = signInWithRoadUserId();
    Http::fake([
        'api.road.test/api/alpha/me/profile' => Http::response(
            ['data' => ['id' => ROAD_USER_UUID, 'name' => 'E', 'email' => 'e@b1.app']],
            200,
        ),
    ]);

    expect(Road::roadUserId())->toBe(ROAD_USER_UUID);
    expect($store->get()?->roadUserId)->toBe(ROAD_USER_UUID);
    expect(Road::roadUserId())->toBe(ROAD_USER_UUID);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $req) => $req->hasHeader('Authorization', 'Bearer at-1'));
});

it('throws, and does not fall back to the Auth Server id, when Road cannot be read', function () {
    $store = signInWithRoadUserId();
    Http::fake([
        'api.road.test/api/alpha/me/profile' => Http::response(['detail' => 'down'], 503),
    ]);

    expect(fn () => Road::roadUserId())->toThrow(RoadException::class);
    expect($store->get()?->roadUserId)->toBeNull();
});

it('is null when no one is signed in', function () {
    Http::fake();

    expect(Road::roadUserId())->toBeNull();
    Http::assertNothingSent();
});

it('survives a token refresh', function () {
    config()->set('road.auth_server.issuer', 'https://auth.test');
    config()->set('road.auth_server.client_id', 'road-api');
    config()->set('road.auth_server.client_secret', 'shh');
    $store = signInWithRoadUserId(ROAD_USER_UUID);

    $fixture = new OidcFixture;
    $fixture->fakeHttp([
        'access_token' => 'at-2',
        'id_token' => $fixture->issueIdToken(['sub' => AUTH_SERVER_SUB]),
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]);

    app(OidcProvider::class)->refresh('rt-1');

    expect($store->get()?->accessToken)->toBe('at-2');
    expect($store->get()?->roadUserId)->toBe(ROAD_USER_UUID);
});
