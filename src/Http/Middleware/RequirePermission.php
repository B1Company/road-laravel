<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Facades\Road;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Declarative permission enforcement via middleware string.
 *
 *   Route::middleware('road.permission:read,Member,buId')
 *        ->get('/bus/{buId}/members', ...);
 *
 *   Route::middleware('road.permission:read,Project,platform:buId')
 *        ->get('/bus/{buId}/projects', ...);
 *
 * Args:
 *   1. action (`create | read | update | delete | manage`)
 *   2. Subject (`BusinessUnit | Member | Role | ...` — PascalCase; or any
 *      platform-defined subject string, e.g. `Project`)
 *   3. scope source: either a route-parameter name (`buId`) or a
 *      request-input key prefixed with `input:` (`input:business_unit_id`).
 *      This is the target. Alone it is the business unit, so Road's catalog
 *      answers. Prefixed with `platform:` (`platform:buId`,
 *      `platform:input:business_unit_id`) it is the unit's subscription to
 *      `road.platform_id`, so your platform's catalog answers.
 *
 * On deny: throws `RoadAuthzException` with the DecisionTrace. Apply
 * the `road.errors` middleware ahead of this one to convert the
 * exception into a 403 JSON response with `error.code = permission_denied`.
 *
 * For programmatic checks, use `Road::can(...)->check()` /
 * `Road::assert(Road::can(...))` directly.
 */
final class RequirePermission
{
    private const PLATFORM_PREFIX = 'platform:';

    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (count($args) < 3) {
            throw new \InvalidArgumentException(
                'road.permission middleware needs exactly 3 args: action, Subject, scopeSource. '
                .'Got: '.implode(',', $args)
            );
        }

        [$actionArg, $subjectArg, $scopeSource] = $args;

        $forPlatform = str_starts_with($scopeSource, self::PLATFORM_PREFIX);
        $scopeId = $this->resolveScopeId(
            $request,
            $forPlatform ? substr($scopeSource, strlen(self::PLATFORM_PREFIX)) : $scopeSource,
        );

        // Action + Subject pass through as raw strings — `Road::can()` accepts
        // the canonical enum or a platform-defined string subject (e.g.
        // `read,Project,platform:buId`), matching @b1-road/nestjs.
        $check = Road::can($actionArg, $subjectArg);
        Road::assert(($forPlatform ? $check->forPlatform() : $check)->in($scopeId));

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function resolveScopeId(Request $request, string $source): string
    {
        if (str_starts_with($source, 'input:')) {
            $key = substr($source, 6);
            $value = $request->input($key);
            if (! is_string($value) || $value === '') {
                throw new \LogicException(
                    "road.permission: request input '$key' is missing or empty (expected the scope id)."
                );
            }

            return $value;
        }

        $value = $request->route($source);
        if (! is_string($value) || $value === '') {
            throw new \LogicException(
                "road.permission: route parameter '$source' is missing or empty (expected the scope id)."
            );
        }

        return $value;
    }
}
