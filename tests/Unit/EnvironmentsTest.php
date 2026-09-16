<?php

declare(strict_types=1);

use B1Road\Laravel\Environments;

/**
 * The hosted environment table and the origin matching behind the boot guard.
 *
 * Mirrored from `ROAD_ENVIRONMENTS` in `@b1-road/types`; the monorepo's
 * `scripts/environment-conformance.mjs` is what stops the two drifting.
 */
it('resolves the hosted API for each environment', function () {
    expect(Environments::apiUrl('sandbox'))->toBe('https://api.road-sandbox.b1.app')
        ->and(Environments::apiUrl('production'))->toBe('https://api.plat.eduzz.com');
});

it('has no hosted URL for a local stack', function () {
    // `local` is a legitimate ROAD_ENVIRONMENT and Plat does not host it, so
    // ROAD_API_BASE_URL stays required there.
    expect(Environments::apiUrl('local'))->toBeNull()
        ->and(Environments::apiUrl(null))->toBeNull();
});

it('matches on origin, not substring', function () {
    // `api.plat.eduzz.com.evil.tld` CONTAINS a production hostname and belongs
    // to whoever owns evil.tld.
    expect(Environments::of('https://api.plat.eduzz.com'))->toBe('production')
        ->and(Environments::of('https://api.plat.eduzz.com.evil.tld'))->toBeNull()
        ->and(Environments::of('https://evil.tld/?x=https://api.plat.eduzz.com'))->toBeNull();
});

it('ignores a default port, which is not part of an origin', function () {
    expect(Environments::of('https://auth.plat.eduzz.com:443'))->toBe('production');
});

it('distinguishes surfaces, so an API URL is not an issuer', function () {
    expect(Environments::of('https://api.plat.eduzz.com', 'auth_server'))->toBeNull()
        ->and(Environments::of('https://auth.plat.eduzz.com', 'auth_server'))->toBe('production')
        ->and(Environments::surfaceOf('https://api.plat.eduzz.com'))
        ->toBe(['environment' => 'production', 'surface' => 'api', 'secure' => true]);
});

it('sees through a terminal DNS dot', function () {
    // The fully-qualified form resolves to the same host and `parse_url` keeps
    // the dot, so it read as an unknown origin and skipped the plaintext guard
    // while the HTTP client went on to the real host. (CodeRabbit, #615.)
    expect(Environments::of('http://api.plat.eduzz.com./'))->toBe('production')
        ->and(Environments::surfaceOf('http://auth.plat.eduzz.com.'))
        ->toBe(['environment' => 'production', 'surface' => 'auth_server', 'secure' => false]);
});

it('says nothing about a host it does not know', function () {
    expect(Environments::of('http://localhost:8080'))->toBeNull()
        ->and(Environments::of('https://sso.acme.example'))->toBeNull()
        ->and(Environments::of('not a url'))->toBeNull();
});
