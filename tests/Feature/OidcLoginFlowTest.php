<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->fixture = new OidcFixture;

    config([
        'road.auth_server.issuer_url' => $this->fixture->issuer,
        'road.auth_server.audience' => $this->fixture->audience,
        'road.auth_server.client_id' => 'test-client',
        'road.auth_server.client_secret' => 'test-secret',
        'road.auth_server.redirect_uri' => 'https://app.test/auth/road/callback',
    ]);
});

it('redirects to the Auth Server authorize endpoint with PKCE params', function () {
    $this->fixture->fakeHttp();

    $response = $this->get('/auth/road/login');

    $response->assertStatus(302);

    $location = (string) $response->headers->get('Location');
    expect($location)->toStartWith($this->fixture->issuer.'/oauth/v2/authorize');
    expect($location)->toContain('response_type=code');
    expect($location)->toContain('code_challenge_method=S256');
    expect($location)->toContain('client_id=test-client');
    expect($location)->toContain('audience=road-api');
});

it('completes the callback and lets a road-protected route resolve Road::user()', function () {
    // Fake the Auth Server once. We don't know the nonce yet — it's
    // generated inside redirectToLogin() — so drive /login first to
    // populate the session, read the nonce, then set the token payload
    // on the fixture (a single fake; no merge-shadowing).
    $this->fixture->fakeHttp();
    $this->get('/auth/road/login');

    $pkce = session()->get('road.oidc.pkce');
    expect($pkce)->toBeArray();

    $idToken = $this->fixture->issueIdToken([
        'sub' => 'u_owner',
        'email' => 'eduardo@b1.app',
        'name' => 'Eduardo',
        'nonce' => $pkce['nonce'],
    ]);

    $this->fixture->tokenResponse = [
        'access_token' => 'fake-at',
        'id_token' => $idToken,
        'refresh_token' => 'fake-rt',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ];

    $callback = $this->get('/auth/road/callback?code=fake-code&state='.$pkce['state']);
    $callback->assertRedirect('/');

    // Tokens now persisted to the session.
    /** @var TokenStore $store */
    $store = $this->app->make(TokenStore::class);
    $tokens = $store->get();
    expect($tokens)->not->toBeNull();
    expect($tokens->accessToken)->toBe('fake-at');

    // Hitting a protected route resolves the user.
    Route::middleware('road')->get('/check-user', function () {
        return Road::user()?->toArray();
    });

    $protected = $this->getJson('/check-user');
    $protected
        ->assertOk()
        ->assertJsonPath('id', 'u_owner')
        ->assertJsonPath('email', 'eduardo@b1.app')
        ->assertJsonPath('name', 'Eduardo');
});

it('returns 401 json on a road-protected api route without a session', function () {
    Route::middleware('road')->get('/protected-api', fn () => ['ok' => true]);

    $response = $this->getJson('/protected-api');

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
});

it('redirects html visitors to the login URL with intended preserved', function () {
    Route::middleware('road')->get('/protected-html', fn () => 'should not render');

    $response = $this->get('/protected-html');

    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/auth/road/login');
    expect($location)->toContain('intended=');
});

it('redirects an HTML callback whose state does not match back to login with error', function () {
    // State mismatch is rejected before any token exchange, so the token
    // payload is irrelevant — a single fake to satisfy discovery is enough.
    $this->fixture->fakeHttp();
    $this->get('/auth/road/login');

    $response = $this->get('/auth/road/callback?code=fake&state=not-the-real-state');
    $response->assertStatus(302);

    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/auth/road/login');
    expect($location)->toContain('error=oidc_state_mismatch');
});

it('returns json on a state-mismatched callback when the caller wants json', function () {
    $this->fixture->fakeHttp();
    $this->get('/auth/road/login');

    $this->getJson('/auth/road/callback?code=fake&state=not-the-real-state')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'oidc_state_mismatch');
});
