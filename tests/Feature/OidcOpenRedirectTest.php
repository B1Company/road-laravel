<?php

declare(strict_types=1);

use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Testing\TestResponse;

/**
 * Open-redirect guard on the OIDC callback (security review SDK-F1 / B1-303).
 *
 * `intended` is attacker-settable on the unauthenticated `GET /auth/road/login`
 * and drives the final `302` after a genuine login. The callback must honor
 * only a same-origin absolute PATH and fall back to `/` for anything that could
 * leave the app's origin — otherwise a link like
 * `…/auth/road/login?intended=https://evil.example/phish` phishes the victim
 * right after they authenticate on the trusted domain (CWE-601).
 */
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

/**
 * Drive the real login → callback dance with a caller-supplied `intended`, and
 * return the callback response so the test can assert where it lands.
 */
function completeLoginWithIntended(OidcFixture $fixture, string $intended): TestResponse
{
    $test = test();
    $fixture->fakeHttp();
    $test->get('/auth/road/login?intended='.rawurlencode($intended));

    $pkce = session()->get('road.oidc.pkce');

    $fixture->tokenResponse = [
        'access_token' => 'fake-at',
        'id_token' => $fixture->issueIdToken([
            'sub' => 'u_owner',
            'email' => 'eduardo@b1.app',
            'name' => 'Eduardo',
            'nonce' => $pkce['nonce'],
        ]),
        'refresh_token' => 'fake-rt',
        'expires_in' => 3600,
        'token_type' => 'Bearer',
    ];

    return $test->get('/auth/road/callback?code=fake-code&state='.$pkce['state']);
}

it('falls back to the app root for an unsafe intended target', function (string $intended) {
    $callback = completeLoginWithIntended($this->fixture, $intended);

    // The attacker-controlled target must NOT reach the Location header — the
    // callback redirects to the app root instead.
    $callback->assertRedirect('/');
})->with([
    'absolute https URL' => ['https://evil.example/phish'],
    'scheme-relative //host' => ['//evil.example/phish'],
    'backslash-normalized //host' => ['/\\evil.example'],
    'non-http scheme' => ['javascript:alert(document.domain)'],
]);

it('preserves a safe same-origin path', function () {
    $callback = completeLoginWithIntended($this->fixture, '/dashboard?tab=widgets');

    // A single-leading-slash path stays on the app origin and is honored.
    $callback->assertRedirect('/dashboard?tab=widgets');
});
