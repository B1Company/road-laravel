<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Context\RoadContext;

/**
 * Pest/PHPUnit trait. Provides `actingAsRoadUser(string $userId)` that
 * sets the request-scoped RoadContext to the scenario user AND seeds the
 * session-backed TokenStore so a subsequent HTTP request through the
 * test client sees an authenticated user.
 */
trait ActsAsRoadUser
{
    /**
     * @param  bool  $supportSession  Sign in as an Eduzz support agent acting
     *                                for this user (plan 69).
     */
    protected function actingAsRoadUser(string $userId, string $email = '', string $name = '', bool $supportSession = false): static
    {
        $user = new RoadUser(
            id: $userId,
            email: $email !== '' ? $email : $userId.'@test.local',
            name: $name !== '' ? $name : $userId,
            payload: ['sub' => $userId, 'email' => $email, 'name' => $name],
        );

        /** @var RoadContext $context */
        $context = $this->app->make(RoadContext::class);
        $context->setUser($user);
        $context->setToken('fake-'.$userId);

        /** @var TokenStore $store */
        $store = $this->app->make(TokenStore::class);
        $store->put(new TokenSet(
            accessToken: 'fake-'.$userId,
            refreshToken: 'fake-rt-'.$userId,
            idToken: null,
            expiresAt: time() + 3600,
            userPayload: [
                'sub' => $userId,
                'email' => $user->email,
                'name' => $user->name,
                'sid' => 'sid-'.$userId,
            ],
            // The scenario names a person by one id, so `Road::roadUserId()`
            // answers it without a profile read.
            roadUserId: $userId,
            // And `Road::supportSession()` answers without a `/me/session` read.
            supportSession: $supportSession,
        ));

        return $this;
    }
}
