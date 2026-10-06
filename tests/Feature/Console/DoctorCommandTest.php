<?php

declare(strict_types=1);

use B1Road\Laravel\Environments;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * Fake the Auth Server (discovery + JWKS, with a fresh Date header for the
 * clock-skew check) and a reachable Road API. Returns the fixture so a test can
 * tweak it. `$extra` adds routes, such as the token endpoint and the Bridge
 * provider probe.
 *
 * @param  array<string, mixed>  $extra
 */
function fakeHealthyDoctorBackend(array $extra = [], string $api = 'api.road.test'): OidcFixture
{
    $fixture = new OidcFixture;

    Http::fake($extra + [
        'auth.test/.well-known/openid-configuration' => Http::response(
            $fixture->discoveryDoc(),
            200,
            ['Date' => gmdate('D, d M Y H:i:s \G\M\T')],
        ),
        'auth.test/oauth/v2/keys' => Http::response($fixture->jwksDoc(), 200),
        // A 401 from the health probe still proves the API is reachable.
        $api.'/api/alpha/iam/identity/auth/config' => Http::response(['ok' => false], 401),
    ]);

    return $fixture;
}

/** A configured service credential, and the token the Auth Server hands it. */
function withDoctorServiceCredential(): void
{
    config([
        'road.service.client_id' => 'svc-client',
        'road.service.client_secret' => 'svc-secret-value',
    ]);
}

/** @return array<string, mixed> */
function doctorTokenEndpoint(): array
{
    return ['auth.test/oauth/v2/token' => Http::response(['access_token' => 'svc-access-token', 'expires_in' => 3600], 200)];
}

it('passes every check against a healthy backend', function () {
    fakeHealthyDoctorBackend();

    $this->artisan('road:doctor')
        ->expectsOutputToContain('All checks passed.')
        ->assertExitCode(0);
});

it('fails and explains when a required config key is missing', function () {
    fakeHealthyDoctorBackend();
    config(['road.auth_server.client_id' => '']);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Auth Server client ID is empty')
        ->expectsOutputToContain('Some checks failed.')
        ->assertExitCode(1);
});

it('reports a broken cache store as its own failure, not as a discovery/JWKS error', function () {
    fakeHealthyDoctorBackend();

    // Reproduce the real fresh-app cliff: the `database` cache store pointed at a
    // sqlite connection whose file doesn't exist — every cache read/write throws a
    // storage error. Bind that repository so OidcDiscovery + JwksCache (which cache
    // through it) hit it. A real store, not a partial mock, so the fix is exercised
    // through the exact path a broken CACHE_STORE takes at runtime.
    config([
        'cache.default' => 'road_test_broken',
        'cache.stores.road_test_broken' => ['driver' => 'database', 'connection' => 'road_test_missing', 'table' => 'cache'],
        'database.connections.road_test_missing' => [
            'driver' => 'sqlite',
            'database' => '/nonexistent/road-doctor/database.sqlite',
        ],
    ]);
    $this->app->forgetInstance(CacheRepository::class);
    $this->app->instance(CacheRepository::class, $this->app->make('cache')->store('road_test_broken'));

    $this->artisan('road:doctor')
        // The cache failure is named as itself…
        ->expectsOutputToContain('Cache store unavailable')
        // …and discovery/JWKS are SKIPPED, not blamed for the storage error.
        ->expectsOutputToContain('Auth Server discovery — skipped')
        ->expectsOutputToContain('JWKS — skipped')
        ->assertExitCode(1);
    // Falsifiability: on the OLD code there is no "skipped" line — discovery/JWKS
    // would each print "failed: …database…" (the storage error) instead, so both
    // expectsOutputToContain above would fail. They pass only because the storage
    // error is now correctly attributed to the cache store, not the Auth Server.
});

it('flags clock skew beyond the 30s window', function () {
    $fixture = new OidcFixture;
    Http::fake([
        // Date header an hour in the past → skew > 30s.
        'auth.test/.well-known/openid-configuration' => Http::response(
            $fixture->discoveryDoc(),
            200,
            ['Date' => gmdate('D, d M Y H:i:s \G\M\T', time() - 3600)],
        ),
        'auth.test/oauth/v2/keys' => Http::response($fixture->jwksDoc(), 200),
        'api.road.test/api/alpha/iam/identity/auth/config' => Http::response(['ok' => false], 401),
    ]);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Clock skew')
        ->assertExitCode(1);
});

// ── The environment it resolves (N21 in plan 68) ────────────────────────────

it('probes the hosted API when only ROAD_ENV is set, and says so', function () {
    // Load config/road.php the way the app does, with ROAD_ENV alone in the env.
    $saved = [$_ENV, $_SERVER];
    foreach (['ROAD_API_BASE_URL', 'ROAD_ENVIRONMENT'] as $name) {
        unset($_ENV[$name], $_SERVER[$name]);
    }
    $_ENV['ROAD_ENV'] = $_SERVER['ROAD_ENV'] = 'production';
    try {
        $road = require __DIR__.'/../../../config/road.php';
    } finally {
        [$_ENV, $_SERVER] = $saved;
    }
    config(['road.environment' => $road['environment'], 'road.api.base_url' => $road['api']['base_url']]);
    fakeHealthyDoctorBackend(api: 'api.plat.eduzz.com');

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Environment: production')
        ->expectsOutputToContain('Road API: '.Environments::HOSTED['production']['api'].' — the hosted URL for production')
        ->expectsOutputToContain('Road API reachable')
        ->assertExitCode(0);

    Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api.plat.eduzz.com/api/alpha/iam/identity/auth/config');
});

it('names ROAD_API_BASE_URL when the URL comes from it', function () {
    fakeHealthyDoctorBackend();

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Road API: https://api.road.test — set by ROAD_API_BASE_URL')
        ->assertExitCode(0);
});

// ── Bridge provider probe (F7.2 in plan 68) ─────────────────────────────────

it('skips the provider probe without failing when no service credential is set', function () {
    fakeHealthyDoctorBackend();

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Bridge provider probe — skipped: no service credential')
        ->expectsOutputToContain('All checks passed.')
        ->assertExitCode(0);

    Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), '/oauth/v2/token'));
});

it('fails a service credential with only one half set', function () {
    fakeHealthyDoctorBackend();
    config(['road.service.client_id' => 'svc-client']);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Service credential incomplete')
        ->assertExitCode(1);
});

it('asks /bridge/authorize as the platform, about a token that cannot be real', function () {
    withDoctorServiceCredential();
    fakeHealthyDoctorBackend(doctorTokenEndpoint() + [
        'api.road.test/api/alpha/bridge/authorize' => Http::response(['status' => 403, 'detail' => 'INVALID_BROKERED_TOKEN', 'code' => 'INVALID_BROKERED_TOKEN'], 403),
    ]);

    $this->artisan('road:doctor')->assertExitCode(0);

    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/oauth/v2/token')
        && $req['grant_type'] === 'client_credentials'
        && $req['client_id'] === 'svc-client');
    Http::assertSent(fn (HttpRequest $req) => $req->url() === 'https://api.road.test/api/alpha/bridge/authorize'
        && $req->method() === 'POST'
        && $req->hasHeader('Authorization', 'Bearer svc-access-token')
        && $req->data() === ['brokeredToken' => 'road-doctor-probe']);
});

it('reports the provider probe outcome', function (int $status, array $body, string $expected, int $exit) {
    withDoctorServiceCredential();
    fakeHealthyDoctorBackend(doctorTokenEndpoint() + [
        'api.road.test/api/alpha/bridge/authorize' => Http::response($body, $status),
    ]);

    $this->artisan('road:doctor')
        ->expectsOutputToContain($expected)
        // Neither the credential nor the token it bought is ever printed.
        ->doesntExpectOutputToContain('svc-secret-value')
        ->doesntExpectOutputToContain('svc-access-token')
        ->assertExitCode($exit);
})->with([
    'ready' => [403, ['status' => 403, 'code' => 'INVALID_BROKERED_TOKEN'], '✓ Bridge provider ready', 0],
    // A consumer-only app holds a credential too, and is never homologated as a
    // provider. That is not a failure.
    'not homologated' => [403, ['status' => 403, 'code' => 'PROVIDER_NOT_HOMOLOGATED'], '⚠ Platform not homologated as a Bridge provider — PROVIDER_NOT_HOMOLOGATED', 0],
    'wrong credential' => [401, ['status' => 401, 'code' => 'UNKNOWN_PROVIDER'], '✗ Service credential belongs to no platform — UNKNOWN_PROVIDER', 1],
    // The global auth guard's answer: the token is not one this API accepts.
    'token from another environment' => [401, ['status' => 401, 'detail' => 'Invalid JWT'], '✗ Road did not accept the service token — HTTP 401', 1],
    // An API from before refusals carried `code` names it in `detail` only.
    'ready, on an API that names the refusal in detail only' => [403, ['status' => 403, 'detail' => 'INVALID_BROKERED_TOKEN'], '✓ Bridge provider ready', 0],
    'any other answer' => [500, ['status' => 500, 'detail' => 'Internal server error'], '✗ Bridge provider probe failed — HTTP 500', 1],
]);

it('names the Auth Server when it rejects the credential, and asks Road nothing', function () {
    withDoctorServiceCredential();
    fakeHealthyDoctorBackend(['auth.test/oauth/v2/token' => Http::response(['error' => 'invalid_client'], 401)]);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('✗ Service credential rejected by the Auth Server — HTTP 401')
        ->assertExitCode(1);

    Http::assertNotSent(fn (HttpRequest $req) => str_contains($req->url(), '/bridge/authorize'));
});

it('tells an unreachable token endpoint apart from a rejected credential', function () {
    withDoctorServiceCredential();
    fakeHealthyDoctorBackend(['auth.test/oauth/v2/token' => fn () => throw new ConnectionException('Connection refused')]);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('✗ Service token request failed — Auth Server token endpoint unreachable')
        ->doesntExpectOutputToContain('rejected by the Auth Server')
        ->assertExitCode(1);
});
