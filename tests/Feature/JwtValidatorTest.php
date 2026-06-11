<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\JwtValidator;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Tests\Support\OidcFixture;

it('verifies a token signed by the Auth Server against the JWKS', function () {
    $fixture = new OidcFixture;
    $fixture->fakeHttp();

    $claims = app(JwtValidator::class)->verify($fixture->issueIdToken(['sub' => 'u_42']));

    expect($claims['sub'])->toBe('u_42');
    expect($claims['iss'])->toBe('https://auth.test');
});

it('rejects a malformed token', function () {
    (new OidcFixture)->fakeHttp();

    expect(fn () => app(JwtValidator::class)->verify('not.a.jwt'))
        ->toThrow(RoadAuthnException::class);
});

it('rejects a token with the wrong issuer', function () {
    $fixture = new OidcFixture;
    $fixture->fakeHttp();

    expect(fn () => app(JwtValidator::class)->verify($fixture->issueIdToken(['iss' => 'https://evil.test'])))
        ->toThrow(RoadAuthnException::class);
});

it('rejects an expired token', function () {
    $fixture = new OidcFixture;
    $fixture->fakeHttp();

    expect(fn () => app(JwtValidator::class)->verify($fixture->issueIdToken(['exp' => time() - 120])))
        ->toThrow(RoadAuthnException::class);
});

it('rejects a token whose audience does not match', function () {
    $fixture = new OidcFixture;
    $fixture->fakeHttp();

    expect(fn () => app(JwtValidator::class)->verify($fixture->issueIdToken(['aud' => 'someone-else'])))
        ->toThrow(RoadAuthnException::class);
});
