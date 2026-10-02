<?php

declare(strict_types=1);

use B1Road\Laravel\Bridge\BridgeAccessDenied;
use B1Road\Laravel\Bridge\BridgeContext;
use B1Road\Laravel\Http\Middleware\EnforceBridgeGrant;
use B1Road\Laravel\Webhooks\Events\BridgeGrantRevoked;
use B1Road\Laravel\Webhooks\Payloads\BridgeGrantWebhookData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

/**
 * `road.bridge` — the Laravel port of `bridgeEnforce()` from
 * `@b1-road/node-core`. The properties pinned here are the ones a provider
 * would otherwise get subtly wrong: the cache key, the tenant and acting-user
 * bindings, the fail mode, and a cache that never outlives the token.
 */
function b64url(array $value): string
{
    return rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
}

/** @param array<string,mixed> $claims */
function brokered(array $claims = [], string $alg = 'RS256'): string
{
    return b64url(['alg' => $alg]).'.'.b64url($claims + ['jti' => 'j1']).'.sig';
}

/** @return array<string,mixed> */
function bridgeCtx(array $overrides = []): array
{
    return $overrides + [
        'leg' => 'bridge',
        'subjectId' => 'consumer-uuid',
        'providerPublicId' => 'plat_psp',
        'permissions' => ['read:Charge'],
    ];
}

/**
 * Fake Road: the Auth Server hands out a service token, attempts are accepted,
 * and `/bridge/authorize` answers with whatever `$authorize` returns.
 */
function fakeRoad(Closure $authorize, ?Closure $attempts = null): void
{
    config([
        'road.service.mode' => 'client_credentials',
        'road.service.client_id' => 'svc-client',
        'road.service.client_secret' => 'svc-secret',
        'road.service.audience' => 'road-api',
        'road.platform_bridge.cache_store' => 'array',
    ]);

    Http::fake(function (HttpRequest $request) use ($authorize, $attempts) {
        $url = $request->url();

        return match (true) {
            str_ends_with($url, '/.well-known/openid-configuration') => Http::response([
                'issuer' => 'https://auth.test',
                'authorization_endpoint' => 'https://auth.test/oauth/v2/authorize',
                'token_endpoint' => 'https://auth.test/oauth/v2/token',
                'jwks_uri' => 'https://auth.test/oauth/v2/keys',
            ]),
            str_ends_with($url, '/oauth/v2/token') => Http::response(['access_token' => 'svc-token', 'expires_in' => 3600]),
            str_ends_with($url, '/bridge/authorize/attempts') => $attempts !== null ? $attempts($request) : Http::response(null, 202),
            str_ends_with($url, '/bridge/authorize') => $authorize($request),
            default => Http::response(null, 404),
        };
    });
}

/** @return list<array<string,mixed>> */
function authorizeBodies(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/bridge/authorize'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

/** @return list<array<string,mixed>> */
function attemptBodies(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/bridge/authorize/attempts'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

function bridgeRoute(string $middleware, string $uri = '/charges'): void
{
    Route::middleware($middleware)->get($uri, fn (Request $request) => response()->json([
        'context' => BridgeContext::of($request)?->toWire(),
        'servedStale' => BridgeContext::of($request)?->servedStale,
    ]));
}

afterEach(function () {
    EnforceBridgeGrant::resolveTenantUsing(null);
    EnforceBridgeGrant::resolveActingUserUsing(null);
});

// ── permission ──────────────────────────────────────────────────────────────

it('allows a permission the token carries and hands the context to the handler', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertOk()
        ->assertJsonPath('context.permissions', ['read:Charge'])
        ->assertJsonPath('servedStale', false);

    // The provider asks as itself, and names the token it is asking about.
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/bridge/authorize')
        && $r->hasHeader('Authorization', 'Bearer svc-token')
        && $r->data() === ['brokeredToken' => brokered()]);
});

// Falsifiability: drop the `! $context->can($permission)` check and the
// handler runs with a verb the token never had.
it('denies a permission the token does not carry', function () {
    Event::fake([BridgeAccessDenied::class]);
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:delete:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'not_authorized');

    Event::assertDispatched(BridgeAccessDenied::class, fn (BridgeAccessDenied $e) => $e->reason === 'not_authorized'
        && $e->permission === 'delete:Charge');
});

it('rejects a request with no bearer token', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges')->assertStatus(401)->assertJsonPath('error', 'missing_token');
    expect(authorizeBodies())->toBe([]);
});

// ── the cache key ───────────────────────────────────────────────────────────

it('asks Road once per token, not once per request', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge');

    foreach (range(1, 3) as $_) {
        $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    }

    expect(authorizeBodies())->toHaveCount(1);
});

// A warm hit answers without Road seeing the token. Keyed on the `jti` (an
// unverified claim), `alg:none` garbage carrying a warm `jti` would be served
// the real token's grants. Falsifiability: key the cache on the `jti` claim
// and the forgery is served from cache with no second Road call.
it('does not serve a forged token the cached grants of a real one with the same jti', function () {
    fakeRoad(fn (HttpRequest $r) => $r->data()['brokeredToken'] === brokered(['jti' => 'j-warm', 'iss' => 'https://auth.real'])
        ? Http::response(['data' => bridgeCtx()])
        : Http::response(['type' => 'x', 'title' => 'INVALID_BROKERED_TOKEN', 'status' => 403], 403));
    bridgeRoute('road.bridge:read:Charge');

    $real = brokered(['jti' => 'j-warm', 'iss' => 'https://auth.real']);
    $forged = brokered(['jti' => 'j-warm', 'iss' => 'https://evil.example'], 'none');

    $this->getJson('/charges', ['Authorization' => "Bearer {$real}"])->assertOk();
    $this->getJson('/charges', ['Authorization' => "Bearer {$forged}"])
        ->assertStatus(403)
        ->assertJsonPath('error', 'not_authorized');

    expect(authorizeBodies())->toHaveCount(2)
        ->and(authorizeBodies()[1]['brokeredToken'])->toBe($forged);
});

// ── expiry (does not inherit B1-472) ────────────────────────────────────────

// Falsifiability: make `usableUntil()` ignore `exp` and the second request is
// answered from a cache entry whose token expired five seconds ago.
it('never serves a cached answer past the token expiry, whatever the TTL', function () {
    $this->freezeTime();
    $exp = now()->getTimestamp() + 10;
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['expiresAt' => $exp])]));
    config(['road.platform_bridge.read_ttl' => 600]);
    bridgeRoute('road.bridge:read:Charge');
    $token = brokered(['exp' => $exp]);

    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->travel(5)->seconds();
    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();
    expect(authorizeBodies())->toHaveCount(1); // still inside the token's life: cached

    $this->travel(10)->seconds();
    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();
    expect(authorizeBodies())->toHaveCount(2); // expired: Road decides again
});

it('bounds the cache by the token exp claim even when Road omits expiresAt', function () {
    $this->freezeTime();
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    config(['road.platform_bridge.read_ttl' => 600]);
    bridgeRoute('road.bridge:read:Charge');
    $token = brokered(['exp' => now()->getTimestamp() + 10]);

    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->travel(11)->seconds();
    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();

    expect(authorizeBodies())->toHaveCount(2);
});

it('does not serve an expired token stale while Road is down', function () {
    $this->freezeTime();
    $exp = now()->getTimestamp() + 10;
    $calls = 0;
    fakeRoad(function () use (&$calls, $exp) {
        if (++$calls === 1) {
            return Http::response(['data' => bridgeCtx(['expiresAt' => $exp])]);
        }
        throw new ConnectionException('Connection refused');
    });
    config(['road.platform_bridge.read_ttl' => 1, 'road.platform_bridge.max_staleness' => 600]);
    bridgeRoute('road.bridge:read:Charge');
    $token = brokered(['exp' => $exp]);

    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])->assertOk();
    $this->travel(11)->seconds();

    $this->getJson('/charges', ['Authorization' => "Bearer {$token}"])
        ->assertStatus(503)
        ->assertJsonPath('error', 'authorization_unavailable');
});

// ── the tenant binding ──────────────────────────────────────────────────────

// Falsifiability: drop the `$requested !== $context->businessUnitId` check and
// a BU-A token serves a BU-B request.
it('refuses an Extensions token against a different business unit', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'install' => 'exti_a', 'businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId,,extensions', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-B/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_tenant');
});

it('allows an Extensions token against its own business unit', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'install' => 'exti_a', 'businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId,,extensions', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertOk()
        ->assertJsonPath('context.businessUnitId', 'bu-a');
});

// B1-618: a Bridge token that names a tenant is bound too. Gating on
// `leg === 'extensions'` would skip this comparison.
it('refuses a Bridge token that names a tenant against a different business unit', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-B/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_tenant');
});

it('refuses a tenant-bound token when the route gives no way to check the tenant', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,,,extensions');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'tenant_unverifiable');
});

it('checks the tenant with a registered resolver when the route names no source', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'businessUnitId' => 'bu-a'])]));
    EnforceBridgeGrant::resolveTenantUsing(fn (Request $r) => $r->header('X-Tenant'));
    bridgeRoute('road.bridge:read:Charge,,,extensions');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered(), 'X-Tenant' => 'bu-B'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_tenant');
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered(), 'X-Tenant' => 'bu-a'])
        ->assertOk();
});

it('leaves a token that names no tenant alone', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
});

// ── the acting-user binding ─────────────────────────────────────────────────

it('refuses a token minted for one end-user against another', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['businessUnitId' => 'bu-a', 'onBehalfOfUser' => 'user-42'])]));
    bridgeRoute('road.bridge:read:Charge,buId,userId', '/bu/{buId}/users/{userId}/charges');

    $this->getJson('/bu/bu-a/users/someone-else/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_user');
    $this->getJson('/bu/bu-a/users/user-42/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertOk();
});

it('refuses an on-behalf-of token when the route gives no way to check the user', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['businessUnitId' => 'bu-a', 'onBehalfOfUser' => 'user-42'])]));
    bridgeRoute('road.bridge:read:Charge,buId', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'acting_user_unverifiable');
});

// ── degraded answers (Road lost the mint record) ────────────────────────────

function fakeDegradedRoad(array $precise): void
{
    fakeRoad(fn (HttpRequest $r) => isset($r->data()['permissions'])
        ? Http::response(['data' => $precise])
        : Http::response(['type' => 'x', 'title' => 'DEGRADED_REQUIRES_EXPLICIT_PERMISSIONS', 'status' => 422], 422));
}

it('re-asks with the explicit permission when Road refuses the broad question, and remembers to', function () {
    fakeDegradedRoad(bridgeCtx(['degraded' => true]));
    bridgeRoute('road.bridge:read:Charge');

    foreach (range(1, 3) as $_) {
        $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    }

    $bodies = authorizeBodies();
    // One broad ask, then only the answerable question. And never cached: an
    // answer about `read:Charge` stored under the token would go on to answer
    // `delete:Charge`, so every request through the window costs a Road call.
    expect($bodies)->toHaveCount(4)
        ->and(array_filter($bodies, fn ($b) => ! isset($b['permissions'])))->toHaveCount(1)
        ->and(array_column(array_slice($bodies, 1), 'permissions'))->toBe(array_fill(0, 3, ['read:Charge']));
});

it('still denies when the precise re-ask says no', function () {
    fakeDegradedRoad(bridgeCtx(['degraded' => true, 'permissions' => []]));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertStatus(403);
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertStatus(403);
});

it('refuses a degraded answer on a tenant-scoped route', function () {
    fakeDegradedRoad(bridgeCtx(['degraded' => true]));
    bridgeRoute('road.bridge:read:Charge,buId', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'degraded_context');
});

// ── the leg (IR-073, D5 in plan 68) ─────────────────────────────────────────

// Falsifiability: drop the leg check and the extension token is served.
it('refuses an Extensions token on a route that did not opt in', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'wrong_leg');
});

it('refuses a Bridge token on an Extensions route', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['businessUnitId' => 'bu-a', 'onBehalfOfUser' => 'user-42'])]));
    bridgeRoute('road.bridge:read:Charge,buId,userId,extensions', '/bu/{buId}/users/{userId}/charges');

    $this->getJson('/bu/bu-a/users/user-42/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'wrong_leg');
});

it("serves both legs on a route that says 'any'", function () {
    fakeRoad(fn (HttpRequest $r) => Http::response(['data' => $r->data()['brokeredToken'] === brokered(['jti' => 'ext'])
        ? bridgeCtx(['leg' => 'extensions', 'businessUnitId' => 'bu-a'])
        : bridgeCtx(['businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId,,any', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered(['jti' => 'ext'])])->assertOk()
        ->assertJsonPath('context.leg', 'extensions');
    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered(['jti' => 'brg'])])->assertOk()
        ->assertJsonPath('context.leg', 'bridge');
});

it('fails loud on a leg it does not know', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge,,,extension');

    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()]))
        ->toThrow(LogicException::class, 'the leg must be');
});

// ── a configured resolver makes the binding mandatory (D5) ──────────────────

// Falsifiability: drop the `$tenantResolver !== null && businessUnitId === null`
// refusal and a token naming no business unit reads a tenant-scoped route.
it('refuses a token that names no business unit on a tenant-scoped route', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge,buId', '/bu/{buId}/charges');

    $this->getJson('/bu/bu-a/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_tenant');
});

// Falsifiability: drop the acting-user counterpart and an unattended token
// reads a route that is about one person.
it('refuses a token minted with no person present on a per-person route', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx(['leg' => 'extensions', 'businessUnitId' => 'bu-a'])]));
    bridgeRoute('road.bridge:read:Charge,buId,userId,extensions', '/bu/{buId}/users/{userId}/charges');

    $this->getJson('/bu/bu-a/users/user-42/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'cross_user');
});

// Falsifiability: gate the degraded refusal on the tenant resolver alone, as it
// was, and this answers `cross_user` instead.
it('refuses a degraded answer when only the person is bound', function () {
    fakeDegradedRoad(bridgeCtx(['degraded' => true]));
    EnforceBridgeGrant::resolveActingUserUsing(fn (Request $r) => $r->header('X-User'));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered(), 'X-User' => 'user-42'])
        ->assertStatus(403)
        ->assertJsonPath('error', 'degraded_context');
});

// ── fail mode ───────────────────────────────────────────────────────────────

it('fails closed with 503 when Road is unreachable and nothing is cached', function () {
    fakeRoad(fn () => throw new ConnectionException('Connection refused'));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(503)
        ->assertJsonPath('error', 'authorization_unavailable');
});

it('fails closed by default even with a stale answer cached', function () {
    $this->freezeTime();
    $calls = 0;
    fakeRoad(function () use (&$calls) {
        if (++$calls === 1) {
            return Http::response(['data' => bridgeCtx()]);
        }
        throw new ConnectionException('Connection refused');
    });
    config(['road.platform_bridge.read_ttl' => 1]);
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->travel(2)->seconds();

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertStatus(503);
});

it('serves last-known-good within max_staleness when opted in, and says so', function () {
    $this->freezeTime();
    $calls = 0;
    fakeRoad(function () use (&$calls) {
        if (++$calls === 1) {
            return Http::response(['data' => bridgeCtx()]);
        }
        throw new ConnectionException('Connection refused');
    });
    config(['road.platform_bridge.read_ttl' => 1, 'road.platform_bridge.max_staleness' => 300]);
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->travel(2)->seconds();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertOk()
        ->assertJsonPath('servedStale', true);

    $this->travel(300)->seconds();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertStatus(503);
});

it('does not answer an explicit refusal from Road with a cached allow', function () {
    $calls = 0;
    fakeRoad(function () use (&$calls) {
        return ++$calls === 1
            ? Http::response(['data' => bridgeCtx()])
            : Http::response(['type' => 'x', 'title' => 'UNKNOWN_TOKEN', 'status' => 403], 403);
    });
    config(['road.platform_bridge.read_ttl' => 0, 'road.platform_bridge.max_staleness' => 600]);
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])
        ->assertStatus(403)
        ->assertJsonPath('error', 'not_authorized');
});

it('fails loud when no service credential is configured', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    config(['road.service.client_id' => null]);
    bridgeRoute('road.bridge:read:Charge');

    $this->withoutExceptionHandling();
    expect(fn () => $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()]))
        ->toThrow(LogicException::class, 'service credential');
});

// ── revocation ──────────────────────────────────────────────────────────────

// Falsifiability: drop the listener registration in RoadServiceProvider and
// the revoked grant keeps answering from cache for the rest of the TTL.
it('drops cached contexts when a bridge.grant.revoked webhook arrives', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    expect(authorizeBodies())->toHaveCount(1);

    event(new BridgeGrantRevoked('evt_1', '2026-09-25T00:00:00Z', new BridgeGrantWebhookData(
        providerPublicId: 'plat_psp',
        subjectPlatformPublicId: 'plat_consumer',
        roleTemplateName: 'reader',
        grantId: 'grant-1',
    )));

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    expect(authorizeBodies())->toHaveCount(2);
});

// A revocation can land while Road is still answering. That answer predates
// the revocation, so it must not be stored where the next request reads it.
// Falsifiability: resolve the generation at write time instead of capturing it
// before the call, and the pre-revocation context is served from cache.
it('does not cache an answer that was in flight when the grant was revoked', function () {
    $calls = 0;
    fakeRoad(function () use (&$calls) {
        if (++$calls === 1) {
            event(new BridgeGrantRevoked('evt_1', '2026-09-25T00:00:00Z', new BridgeGrantWebhookData(
                providerPublicId: 'plat_psp',
                subjectPlatformPublicId: 'plat_consumer',
                roleTemplateName: 'reader',
                grantId: 'grant-1',
            )));
        }

        return Http::response(['data' => bridgeCtx()]);
    });
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();

    expect(authorizeBodies())->toHaveCount(2);
});

// The degraded marker is only phrasing, but it is written from the same
// request and must follow the same rule: a revocation mid-request leaves it
// where nobody reads it, so the next request starts from the broad question.
it('does not keep a degraded marker written across a revocation', function () {
    $revoked = false;
    fakeRoad(function (HttpRequest $r) use (&$revoked) {
        if (isset($r->data()['permissions'])) {
            return Http::response(['data' => bridgeCtx(['degraded' => true])]);
        }
        // The revocation lands while Road is answering the broad question,
        // i.e. before the middleware writes its marker.
        if (! $revoked) {
            $revoked = true;
            event(new BridgeGrantRevoked('evt_1', '2026-09-25T00:00:00Z', new BridgeGrantWebhookData(
                providerPublicId: 'plat_psp',
                subjectPlatformPublicId: 'plat_consumer',
                roleTemplateName: 'reader',
                grantId: 'grant-1',
            )));
        }

        return Http::response(['type' => 'x', 'title' => 'DEGRADED_REQUIRES_EXPLICIT_PERMISSIONS', 'status' => 422], 422);
    });
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();

    expect(array_filter(authorizeBodies(), fn ($b) => ! isset($b['permissions'])))->toHaveCount(2);
});

// ── attempt reporting ───────────────────────────────────────────────────────

// Falsifiability: send the report from handle() before `$next` and the handler
// sees one already on the wire.
it('reports the attempt after the response, without the query string', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    $seenByHandler = null;
    Route::middleware('road.bridge:read:Charge')->get('/charges/{id}', function () use (&$seenByHandler) {
        $seenByHandler = count(attemptBodies());

        return response()->json(['ok' => true]);
    });

    $this->getJson('/charges/88?email=someone@example.com', ['Authorization' => 'Bearer '.brokered()])->assertOk();

    expect($seenByHandler)->toBe(0)
        ->and(attemptBodies())->toBe([[
            'brokeredToken' => brokered(),
            'permission' => 'read:Charge',
            'allowed' => true,
            'method' => 'GET',
            'path' => '/charges/88',
        ]]);
});

it('reports a denied attempt too', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]));
    bridgeRoute('road.bridge:delete:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertStatus(403);

    expect(attemptBodies())->toHaveCount(1)
        ->and(attemptBodies()[0]['allowed'])->toBeFalse();
});

it('still serves the request when reporting the attempt fails', function () {
    fakeRoad(fn () => Http::response(['data' => bridgeCtx()]), fn () => Http::response(null, 500));
    bridgeRoute('road.bridge:read:Charge');

    $this->getJson('/charges', ['Authorization' => 'Bearer '.brokered()])->assertOk();
});
