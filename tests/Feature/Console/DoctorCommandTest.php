<?php

declare(strict_types=1);

use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Http;

/**
 * Fake the Auth Server (discovery + JWKS, with a fresh Date header for the
 * clock-skew check) and a reachable Road API. Returns the fixture so a test can
 * tweak it.
 */
function fakeHealthyDoctorBackend(): OidcFixture
{
    $fixture = new OidcFixture;

    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response(
            $fixture->discoveryDoc(),
            200,
            ['Date' => gmdate('D, d M Y H:i:s \G\M\T')],
        ),
        'auth.test/oauth/v2/keys' => Http::response($fixture->jwksDoc(), 200),
        // A 401 from the health probe still proves the API is reachable.
        'api.road.test/api/alpha/iam/identity/auth/config' => Http::response(['ok' => false], 401),
    ]);

    return $fixture;
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
