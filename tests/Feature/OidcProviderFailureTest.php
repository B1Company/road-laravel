<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Tests\Support\OidcFixture;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Covers OidcProvider's error and edge paths (B1-311): the token-exchange and
 * refresh failure branches, and logout when the Auth Server publishes no
 * `end_session_endpoint`. The happy path is covered by OidcLoginFlowTest; these
 * are the branches that only run when something has already gone wrong, which
 * is exactly the category that rots unnoticed.
 *
 * Every assertion checks errorCode(), not just the exception class — the whole
 * file throws RoadAuthnException, so the class alone cannot tell a network
 * failure from a 400 from a malformed body.
 */
beforeEach(function () {
    config()->set('road.auth_server.issuer', 'https://auth.test');
    config()->set('road.auth_server.client_id', 'road-api');
    config()->set('road.auth_server.client_secret', 'shh');
    config()->set('road.auth_server.redirect_uri', 'http://localhost/auth/road/callback');
});

/**
 * Stub the Auth Server with a caller-supplied token-endpoint response, then
 * drive /login so the PKCE verifier + state land in the session.
 *
 * Http::fake() MERGES stub maps across calls and the FIRST matching stub wins
 * (verified empirically, and documented in OidcFixture::$tokenResponse). So the
 * token stub must be in place before any fake() call — calling
 * OidcFixture::fakeHttp() first and overriding afterwards silently leaves its
 * 200 in place, and the test then exercises a completely different branch.
 *
 * @param  mixed  $tokenStub  What Http::fake() accepts for one URL: a promise,
 *                            a Response, or a closure returning either.
 * @return array<string,string>
 */
function primePkce(OidcFixture $fixture, mixed $tokenStub): array
{
    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        $fixture->issuer.'/oauth/v2/keys' => Http::response($fixture->jwksDoc(), 200),
        $fixture->issuer.'/oauth/v2/token' => $tokenStub,
    ]);

    $case = test();
    $case->get('/auth/road/login');

    /** @var array<string,string> $pkce */
    $pkce = session()->get('road.oidc.pkce');

    return $pkce;
}

it('raises oidc_token_exchange_failed when the token endpoint is unreachable', function () {
    $fixture = new OidcFixture;
    $pkce = primePkce($fixture, fn () => throw new ConnectionException('token endpoint down'));

    try {
        app(OidcProvider::class)->handleCallback(request()->merge([
            'code' => 'fake-code',
            'state' => $pkce['state'],
        ]));
        throw new RuntimeException('Expected a RoadAuthnException when the token endpoint is unreachable.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('oidc_token_exchange_failed');
        expect($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('raises oidc_token_exchange_failed on a non-2xx from the token endpoint', function () {
    $fixture = new OidcFixture;
    $pkce = primePkce($fixture, Http::response(['error' => 'invalid_grant'], 400));

    try {
        app(OidcProvider::class)->handleCallback(request()->merge([
            'code' => 'expired-code',
            'state' => $pkce['state'],
        ]));
        throw new RuntimeException('Expected a RoadAuthnException for a 400 token response.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('oidc_token_exchange_failed');
        expect($e->getMessage())->toContain('400');
        // The Auth Server's body is carried through so the cause is diagnosable.
        expect($e->payload()['response'] ?? '')->toContain('invalid_grant');
    }
});

it('raises oidc_token_exchange_failed when the token response has no access_token', function () {
    $fixture = new OidcFixture;
    // 200 OK, but the payload is missing access_token — a spec-violating server.
    $pkce = primePkce($fixture, Http::response(['token_type' => 'Bearer', 'expires_in' => 3600], 200));

    try {
        app(OidcProvider::class)->handleCallback(request()->merge([
            'code' => 'fake-code',
            'state' => $pkce['state'],
        ]));
        throw new RuntimeException('Expected a RoadAuthnException for a token response with no access_token.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('oidc_token_exchange_failed');
        expect($e->getMessage())->toContain('access_token');
    }
});

it('raises oidc_refresh_failed when the refresh request cannot be sent', function () {
    $fixture = new OidcFixture;

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        $fixture->issuer.'/oauth/v2/token' => fn () => throw new ConnectionException('refresh unreachable'),
    ]);

    try {
        app(OidcProvider::class)->refresh('a-refresh-token');
        throw new RuntimeException('Expected a RoadAuthnException when the refresh request fails.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('oidc_refresh_failed');
        expect($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('raises oidc_refresh_failed on a non-2xx refresh response', function () {
    $fixture = new OidcFixture;

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($fixture->discoveryDoc(), 200),
        // The canonical case: the refresh token was revoked or expired.
        $fixture->issuer.'/oauth/v2/token' => Http::response(['error' => 'invalid_grant'], 401),
    ]);

    try {
        app(OidcProvider::class)->refresh('a-revoked-token');
        throw new RuntimeException('Expected a RoadAuthnException for a 401 refresh response.');
    } catch (RoadAuthnException $e) {
        expect($e->errorCode())->toBe('oidc_refresh_failed');
        expect($e->getMessage())->toContain('401');
    }
});

it('redirects to the app root on logout when the Auth Server has no end_session_endpoint', function () {
    $fixture = new OidcFixture;

    // Same discovery doc minus end_session_endpoint — an Auth Server that does
    // not implement RP-initiated logout. Without the null guard this would
    // build a redirect to `?id_token_hint=…` against an empty base URL.
    $discovery = $fixture->discoveryDoc();
    unset($discovery['end_session_endpoint']);

    Http::fake([
        $fixture->issuer.'/.well-known/openid-configuration' => Http::response($discovery, 200),
        $fixture->issuer.'/oauth/v2/keys' => Http::response($fixture->jwksDoc(), 200),
    ]);

    // logout() reads and clears the PKCE session entry, so the request needs a
    // live session store — request() in a test has none by default.
    $request = request();
    $request->setLaravelSession(session()->driver());

    $response = app(OidcProvider::class)->logout($request);

    expect($response->getTargetUrl())->toBe($request->root());
    // Not a redirect at the Auth Server, and no dangling query string.
    expect($response->getTargetUrl())->not->toContain('id_token_hint');
    expect($response->getTargetUrl())->not->toContain($fixture->issuer);
});
