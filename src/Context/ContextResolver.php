<?php

declare(strict_types=1);

namespace B1Road\Laravel\Context;

use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\RoadUser;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated Road user for the current request.
 *
 * Single path: session-backed token store → RoadContext. There is no Bearer
 * branch and no auto-detection — this SDK is BFF-only by design
 * (SDK_DX_BAR §2: one resolver, not a mode switch).
 */
final class ContextResolver
{
    public function __construct(
        private readonly TokenStore $tokenStore,
        private readonly RoadContext $context,
    ) {
    }

    /**
     * Populate the request-scoped RoadContext from the session's TokenSet.
     * Returns true if a user was resolved; false if no session/tokens.
     */
    public function resolveFromRequest(Request $request): bool
    {
        $tokens = $this->tokenStore->get();
        if ($tokens === null) {
            return false;
        }

        $payload = $tokens->userPayload;

        $this->context->setUser(new RoadUser(
            id: (string) ($payload['sub'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            name: (string) ($payload['name'] ?? $payload['preferred_username'] ?? ''),
            avatarUrl: isset($payload['picture']) ? (string) $payload['picture'] : null,
            payload: $payload,
        ));
        $this->context->setToken($tokens->accessToken);

        $incomingRequestId = $request->headers->get('X-Request-Id');
        if (is_string($incomingRequestId) && $incomingRequestId !== '') {
            $this->context->setRequestId($incomingRequestId);
        }

        return true;
    }
}
