<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth;

use B1Road\Laravel\Context\RoadContext;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;

/**
 * Bridges Road's RoadContext to Laravel's auth('road')->user() API.
 *
 * Population is upstream — EnsureRoadAuthenticated middleware fills
 * RoadContext from the session-backed TokenStore. The guard only reads.
 */
final class RoadGuard implements Guard
{
    use GuardHelpers;

    public function __construct(
        UserProvider $provider,
        private readonly RoadContext $context,
    ) {
        $this->provider = $provider;
    }

    public function user(): ?Authenticatable
    {
        return $this->context->user();
    }

    /** @param  array<string,mixed>  $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user() !== null;
    }
}
