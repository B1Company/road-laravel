<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\JwksCache;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Jose\Component\KeyManagement\JWKFactory;

/**
 * Covers JwksCache's rotation and failure paths (B1-311). The happy path is
 * exercised indirectly by JwtValidatorTest; what was untested is what happens
 * when the Auth Server rotates keys or its JWKS endpoint misbehaves — the
 * paths that only run when something is already going wrong.
 *
 * These build their own Http::fake() map rather than calling
 * OidcFixture::fakeHttp() first: fake() MERGES stub maps across calls and the
 * earliest matching stub wins, so a later override of the JWKS URL would be
 * shadowed by the fixture's 200 (see the $tokenResponse docblock in
 * OidcFixture for the same footgun).
 */
it('re-fetches the JWKS on refresh so a rotated key is picked up', function () {
    $fixture = new OidcFixture;

    // A second keypair standing in for the Auth Server's post-rotation key.
    $rotated = JWKFactory::createRSAKey(
        2048,
        ['alg' => 'RS256', 'use' => 'sig', 'kid' => 'road-rotated-key-2']
    )->toPublic();

    $servedKeys = $fixture->jwksDoc();

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        // Bound by reference so the test can swap the served key set mid-flight;
        // Http::fake() re-invokes the closure on every request.
        $fixture->issuer.'/oauth/v2/keys' => function () use (&$servedKeys) {
            return Http::response($servedKeys, 200);
        },
        // Catch-all so an unstubbed URL fails deterministically instead of
        // attempting a real connection (Guzzle otherwise rejects with a genuine
        // network error, masking a changed URL as a transport bug).
        '*' => Http::response(['error' => 'unstubbed_url_in_test'], 599),
    ]);

    $cache = app(JwksCache::class);

    // Prime the cache with the original key.
    expect($cache->get()->has($fixture->kid))->toBeTrue();

    // The Auth Server rotates: same endpoint, different key.
    $servedKeys = ['keys' => [$rotated->all()]];

    // get() must still serve the STALE cached set — proving the cache is real,
    // so the assertion below is about refresh() evicting it, not about the
    // fake simply having changed.
    expect($cache->get()->has('road-rotated-key-2'))->toBeFalse();

    // refresh() forgets the cache key and re-fetches.
    $refreshed = $cache->refresh();

    expect($refreshed->has('road-rotated-key-2'))->toBeTrue();
    expect($refreshed->has($fixture->kid))->toBeFalse();
});

it('raises jwks_unreachable when the JWKS endpoint cannot be reached', function () {
    $fixture = new OidcFixture;

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        $fixture->issuer.'/oauth/v2/keys' => fn () => throw new ConnectionException('connection reset'),
        // Catch-all so an unstubbed URL fails deterministically instead of
        // attempting a real connection (Guzzle otherwise rejects with a genuine
        // network error, masking a changed URL as a transport bug).
        '*' => Http::response(['error' => 'unstubbed_url_in_test'], 599),
    ]);

    expect(fn () => app(JwksCache::class)->get())
        ->toThrow(
            RoadAuthnException::class,
            'Failed to fetch Auth Server JWKS.'
        );

    // Assert the machine-readable code, not just the class: all three failure
    // modes throw RoadAuthnException, so the class alone would pass on any of
    // them and the test would not distinguish a transport failure from a 500.
    try {
        app(JwksCache::class)->get();
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('jwks_unreachable');
        expect($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('raises jwks_fetch_failed when the JWKS endpoint returns a non-2xx', function () {
    $fixture = new OidcFixture;

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        $fixture->issuer.'/oauth/v2/keys' => Http::response(['error' => 'boom'], 503),
        // Catch-all so an unstubbed URL fails deterministically instead of
        // attempting a real connection (Guzzle otherwise rejects with a genuine
        // network error, masking a changed URL as a transport bug).
        '*' => Http::response(['error' => 'unstubbed_url_in_test'], 599),
    ]);

    try {
        app(JwksCache::class)->get();
        $this->fail('Expected a RoadAuthnException for a 503 JWKS response.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('jwks_fetch_failed');
        // The status is surfaced so an operator can tell 503 from 404.
        expect($e->getMessage())->toContain('503');
    }
});

it('raises jwks_invalid when the JWKS response has no keys array', function () {
    $fixture = new OidcFixture;

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        // 200 OK, but not a key set — e.g. a proxy returning an HTML error page.
        $fixture->issuer.'/oauth/v2/keys' => Http::response(['not_keys' => []], 200),
        // Catch-all so an unstubbed URL fails deterministically instead of
        // attempting a real connection (Guzzle otherwise rejects with a genuine
        // network error, masking a changed URL as a transport bug).
        '*' => Http::response(['error' => 'unstubbed_url_in_test'], 599),
    ]);

    try {
        app(JwksCache::class)->get();
        $this->fail('Expected a RoadAuthnException for a malformed JWKS body.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('jwks_invalid');
    }
});
