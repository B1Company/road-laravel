<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\CacheTokenStore;
use B1Road\Laravel\Auth\AuthServer\SessionTokenStore;
use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Session\Session;

/**
 * The `cache` token-store driver (P2) keeps BFF tokens in a shared cache (Redis
 * in prod) keyed by session id, for horizontally-scaled / Octane BFFs. Selected
 * via `road.token_store = cache`.
 */
function sampleTokens(): TokenSet
{
    return new TokenSet(
        accessToken: 'at',
        refreshToken: 'rt',
        idToken: 'it',
        expiresAt: time() + 3600,
        userPayload: ['sub' => 'u_1', 'email' => 'u@u.com'],
    );
}

it('round-trips tokens through the cache keyed by session id', function () {
    /** @var Cache $cache */
    $cache = app(Cache::class);
    /** @var Session $session */
    $session = app(Session::class);
    $store = new CacheTokenStore($cache, $session, ttlSeconds: 600);

    expect($store->get())->toBeNull();

    $store->put(sampleTokens());

    // Stored under road:session:{id} in the cache — not the session payload.
    expect($cache->get('road:session:'.$session->getId()))->toBeArray();
    expect($session->get('road.tokens'))->toBeNull();

    $got = $store->get();
    expect($got)->toBeInstanceOf(TokenSet::class);
    expect($got->accessToken)->toBe('at');
    expect($got->userPayload['sub'])->toBe('u_1');

    $store->clear();
    expect($store->get())->toBeNull();
    expect($cache->get('road:session:'.$session->getId()))->toBeNull();
});

it('the provider binds the cache driver when road.token_store=cache', function () {
    config(['road.token_store' => 'cache']);
    // Re-resolve the scoped binding under the new config.
    app()->forgetInstance(TokenStore::class);

    expect(app(TokenStore::class))->toBeInstanceOf(CacheTokenStore::class);
});

it('the provider binds the session driver by default', function () {
    config(['road.token_store' => 'session']);
    app()->forgetInstance(TokenStore::class);

    expect(app(TokenStore::class))->toBeInstanceOf(SessionTokenStore::class);
});
