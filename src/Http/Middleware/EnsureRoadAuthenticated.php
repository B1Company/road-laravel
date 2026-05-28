<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Context\ContextResolver;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Resolves the current Road user from the session-backed TokenStore and
 * populates RoadContext. Refreshes expired tokens silently when a
 * refresh_token is available; 401s (API) or redirects to /auth/road/login
 * (HTML) otherwise.
 */
class EnsureRoadAuthenticated
{
    public function __construct(
        protected readonly TokenStore $tokenStore,
        protected readonly OidcProvider $oidc,
        protected readonly ContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->ensureAuthenticated($request)) {
            return $this->unauthorized($request);
        }

        return $next($request);
    }

    protected function ensureAuthenticated(Request $request): bool
    {
        $tokens = $this->tokenStore->get();
        if ($tokens === null) {
            return false;
        }

        if ($tokens->isExpired() && $tokens->refreshToken !== null) {
            try {
                $this->oidc->refresh($tokens->refreshToken);
            } catch (Throwable) {
                $this->tokenStore->clear();

                return false;
            }
        } elseif ($tokens->isExpired()) {
            $this->tokenStore->clear();

            return false;
        }

        return $this->resolver->resolveFromRequest($request);
    }

    protected function unauthorized(Request $request): Response
    {
        if ($this->wantsJson($request)) {
            $exception = new RoadAuthnException;

            return new JsonResponse(
                ['error' => $exception->toErrorBody()],
                $exception->httpStatus(),
            );
        }

        $loginUrl = url('/auth/road/login').'?'.http_build_query(['intended' => $request->fullUrl()]);

        return new RedirectResponse($loginUrl);
    }

    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->wantsJson() || $request->is('road-api/*');
    }
}
