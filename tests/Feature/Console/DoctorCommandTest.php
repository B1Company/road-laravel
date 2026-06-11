<?php

declare(strict_types=1);

use B1Road\Laravel\Tests\Support\OidcFixture;
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
