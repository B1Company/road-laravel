<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth;

use B1Road\Laravel\Context\RoadContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Road's UserProvider is read-only — there is no local user table. Lookups
 * are answered from the request-scoped RoadContext that
 * EnsureRoadAuthenticated populated from the BFF token store.
 */
final class RoadUserProvider implements UserProvider
{
    public function __construct(private readonly RoadContext $context)
    {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        $user = $this->context->user();
        if ($user === null) {
            return null;
        }

        return $user->id === (string) $identifier ? $user : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
    }

    /** @param  array<string,mixed>  $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    /** @param  array<string,mixed>  $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}
