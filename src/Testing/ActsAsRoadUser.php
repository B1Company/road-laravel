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
    protected function actingAsRoadUser(string $userId, string $email = '', string $name = ''): static
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
            ],
        ));

        return $this;
    }
}
