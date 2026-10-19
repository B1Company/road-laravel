<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Inertia\ShareRoadContext;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

uses(ActsAsRoadUser::class);

/**
 * `Road::supportSession()` — whether an Eduzz support agent opened the
 * session on the person's behalf (plan 69, B1-770). Read from `/me/session`
 * with the ID token's `sid` once per session and kept in the TokenSet, the way
 * `Road::roadUserId()` is. Unknown is `null`, never a silent `false`.
 */
const SUPPORT_SUB = '312004937762963472';
const SUPPORT_SID = '395805716146873749';

function signInForSupport(?bool $supportSession = null, array $payload = ['sub' => SUPPORT_SUB, 'sid' => SUPPORT_SID]): TokenStore
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: SUPPORT_SUB, email: 'e@b1.app', name: 'E', payload: $payload));
    $ctx->setToken('at-1');

    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    $store->put(new TokenSet(
        accessToken: 'at-1',
        refreshToken: 'rt-1',
        idToken: null,
        expiresAt: time() + 3600,
        userPayload: $payload,
        supportSession: $supportSession,
    ));

    return $store;
}

it('returns the stored answer without asking Road', function () {
    signInForSupport(true);
    Http::fake();

    expect(Road::supportSession())->toBeTrue();
    Http::assertNothingSent();
});

it('reads it once with the ID token sid, and keeps it', function () {
    $store = signInForSupport();
    Http::fake([
        'api.road.test/api/alpha/me/session*' => Http::response(['data' => ['supportSession' => true]], 200),
    ]);

    expect(Road::supportSession())->toBeTrue();
    expect($store->get()?->supportSession)->toBeTrue();
    expect(Road::supportSession())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $req) => $req->hasHeader('Authorization', 'Bearer at-1')
        && ($req->data()['sessionId'] ?? null) === SUPPORT_SID);
});

it('is unknown (null), not false, when Road cannot answer, and asks again next time', function () {
    $store = signInForSupport();
    Http::fake([
        'api.road.test/api/alpha/me/session*' => Http::response(['detail' => 'down'], 503),
    ]);

    expect(Road::supportSession())->toBeNull();
    expect($store->get()?->supportSession)->toBeNull();
    $afterFirst = count(Http::recorded());

    // Nothing was kept, so the next call asks Road again.
    expect(Road::supportSession())->toBeNull();
    expect(count(Http::recorded()))->toBe(2 * $afterFirst);
});

it('treats an answer without a boolean as unknown, and keeps nothing', function () {
    $store = signInForSupport();
    Http::fake([
        'api.road.test/api/alpha/me/session*' => Http::response(['data' => ['supportSession' => 'yes']], 200),
    ]);

    expect(Road::supportSession())->toBeNull();
    expect($store->get()?->supportSession)->toBeNull();
});

it('lets a later withUser reset the support flag', function () {
    $scenario = RoadScenario::make()->withUser('u_1', supportSession: true)->withUser('u_1');

    expect($scenario->isSupportSession('u_1'))->toBeFalse();
});

it('the fake answers 404 for a session id that is not the caller\'s', function () {
    Road::fake(RoadScenario::make()->withUser('u_agent', supportSession: true));
    $this->actingAsRoadUser('u_agent');

    expect(fn () => Road::client()->me()->supportSession('sid-someone-else'))
        ->toThrow(RoadNotFoundException::class);
    expect(Road::client()->me()->supportSession('sid-u_agent'))->toBeTrue();
});

it('is unknown (null), never false, on a 404, and keeps nothing', function () {
    $store = signInForSupport();
    Http::fake([
        'api.road.test/api/alpha/me/session*' => Http::response(['code' => 'SESSION_NOT_FOUND'], 404),
    ]);

    expect(Road::supportSession())->toBeNull();
    expect($store->get()?->supportSession)->toBeNull();
});

it('is unknown, without asking, for an ID token that carries no sid', function () {
    signInForSupport(payload: ['sub' => SUPPORT_SUB]);
    Http::fake();

    expect(Road::supportSession())->toBeNull();
    Http::assertNothingSent();
});

it('is null when no one is signed in', function () {
    Http::fake();

    expect(Road::supportSession())->toBeNull();
    Http::assertNothingSent();
});

it('survives a token refresh', function () {
    config()->set('road.auth_server.issuer', 'https://auth.test');
    config()->set('road.auth_server.client_id', 'road-api');
    config()->set('road.auth_server.client_secret', 'shh');
    $store = signInForSupport(true);

    $fixture = new OidcFixture;
    $fixture->fakeHttp([
        'access_token' => 'at-2',
        'id_token' => $fixture->issueIdToken(['sub' => SUPPORT_SUB]),
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ]);

    app(OidcProvider::class)->refresh('rt-1');

    expect($store->get()?->accessToken)->toBe('at-2');
    expect($store->get()?->supportSession)->toBeTrue();
});

it('round-trips through the session store', function () {
    $tokens = new TokenSet('at', 'rt', null, time() + 60, ['sub' => 'x'], supportSession: true);

    expect(TokenSet::fromArray($tokens->toArray())->supportSession)->toBeTrue();
    expect(TokenSet::fromArray([...$tokens->toArray(), 'supportSession' => null])->supportSession)->toBeNull();
});

it('takes the answer actingAsRoadUser gives, without a read', function () {
    // The scenario says the opposite on purpose: only the stored answer can
    // produce these results, so a read through the fake would fail them.
    Road::fake(RoadScenario::make()->withUser('u_agent')->withUser('u_self', supportSession: true));

    $this->actingAsRoadUser('u_agent', supportSession: true);
    expect(Road::supportSession())->toBeTrue();

    $this->actingAsRoadUser('u_self');
    expect(Road::supportSession())->toBeFalse();
});

it('reads it from the fake backend for a session without the answer', function () {
    Road::fake(RoadScenario::make()->withUser('u_agent', supportSession: true));
    $this->actingAsRoadUser('u_agent');
    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    $tokens = $store->get();
    $store->put(new TokenSet(
        $tokens->accessToken, $tokens->refreshToken, null, $tokens->expiresAt, $tokens->userPayload, $tokens->roadUserId,
    ));

    expect(Road::supportSession())->toBeTrue();
});

it('shares it with Inertia on the user prop', function () {
    Http::fake(['*' => Http::response([], 500)]);
    signInForSupport(true);
    $request = Request::create('/dashboard', 'GET');
    $request->setLaravelSession($this->app->make('session')->driver());

    $this->app->make(ShareRoadContext::class)->handle($request, fn ($req) => response('ok'));

    $shared = Inertia::getShared('road');
    $props = is_callable($shared) ? $shared() : $shared;
    expect($props['user']['supportSession'])->toBeTrue();
    expect($props['user']['id'])->toBe(SUPPORT_SUB);
});
