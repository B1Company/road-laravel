<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;

/** An in-memory TokenStore seeded with (or without) a token set. */
function fakeTokenStore(?TokenSet $tokens): TokenStore
{
    return new class($tokens) implements TokenStore
    {
        public function __construct(private ?TokenSet $tokens) {}

        public function get(): ?TokenSet
        {
            return $this->tokens;
        }

        public function put(TokenSet $tokens): void
        {
            $this->tokens = $tokens;
        }

        public function clear(): void
        {
            $this->tokens = null;
        }
    };
}

it('warns and fails when no session tokens are stored', function () {
    app()->instance(TokenStore::class, fakeTokenStore(null));

    $this->artisan('road:whoami')
        ->expectsOutputToContain('No session-stored Road tokens found.')
        ->assertExitCode(1);
});

it('prints the stored user claims', function () {
    app()->instance(TokenStore::class, fakeTokenStore(new TokenSet(
        accessToken: 'at',
        refreshToken: 'rt',
        idToken: 'it',
        expiresAt: time() + 3600,
        userPayload: ['sub' => 'u_42', 'email' => 'eduardo@b1.app', 'name' => 'Eduardo'],
    )));

    $this->artisan('road:whoami')
        ->expectsOutputToContain('u_42')
        ->expectsOutputToContain('eduardo@b1.app')
        ->expectsOutputToContain('refresh available: yes')
        ->assertExitCode(0);
});
