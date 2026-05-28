<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Like EnsureRoadAuthenticated but does not 401/redirect when no session is
 * present — Road::user() simply returns null downstream. Useful for routes
 * that render differently for guests vs members.
 */
final class EnsureRoadAuthenticatedOptional extends EnsureRoadAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->ensureAuthenticated($request);

        return $next($request);
    }
}
