<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;

/**
 * Drives the REAL `/road/business-unit` route through the full middleware
 * stack — unlike BusinessUnitRouteTest, which news up the controller with a
 * hand-started session and so cannot see the route's middleware.
 *
 * The distinction is load-bearing: the `road` guard resolves the caller from
 * the session-backed TokenStore, which is only populated once StartSession
 * (from the `web` group) has run. Drop `web` from the route group and every
 * call 401s "unauthenticated" for a logged-in browser — a regression that
 * shipped once and was only caught driving Beacon in a browser. This test
 * fails the moment `web` is removed from routes/auth.php.
 */
function seedBusinessUnitSession(): void
{
    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    $store->put(new TokenSet(
        accessToken: 'sess-bearer-bu',
        refreshToken: 'rt',
        idToken: null,
        expiresAt: time() + 3600,
        userPayload: [
            'sub' => 'u_switch',
            'email' => 'switch@example.com',
            'name' => 'Switch User',
        ],
    ));
}

it('sets the current business unit through the real route with a live session', function () {
    seedBusinessUnitSession();

    $response = $this->postJson('/road/business-unit', ['id' => 'bu_switch']);

    $response->assertOk()->assertJsonPath('currentBusinessUnitId', 'bu_switch');
});

it('clears the current business unit through the real route', function () {
    seedBusinessUnitSession();
    $this->postJson('/road/business-unit', ['id' => 'bu_switch'])->assertOk();

    $this->deleteJson('/road/business-unit')
        ->assertOk()
        ->assertJsonPath('currentBusinessUnitId', null);
});

it('401s the switch route when no session token is present', function () {
    $this->postJson('/road/business-unit', ['id' => 'bu_switch'])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('ends the BFF session on logout so the caller is no longer authenticated', function () {
    seedBusinessUnitSession();

    // Sanity: authenticated before logout.
    $this->getJson('/road/whoami')->assertOk();

    // Logout clears the session-backed token store; end_session redirect follows.
    $this->post('/auth/road/logout')->assertRedirect();

    // The token store must be empty and protected routes must 401 — a logout
    // that leaves the session live is a security bug (found driving Beacon:
    // after Sign out, /road/whoami still returned the logged-in user).
    expect(app(TokenStore::class)->get())->toBeNull();
    $this->getJson('/road/whoami')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});
