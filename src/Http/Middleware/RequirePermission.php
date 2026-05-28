<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Subject;
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
 * Args:
 *   1. action (`create | read | update | delete | manage`)
 *   2. Subject (`BusinessUnit | Member | Role | ...` — PascalCase)
 *   3. scope source: either a route-parameter name (`buId`) or a
 *      request-input key prefixed with `input:` (`input:business_unit_id`)
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
    public function handle(Request $request, Closure $next, string ...$args): Response
    {
        if (count($args) < 3) {
            throw new \InvalidArgumentException(
                'road.permission middleware needs exactly 3 args: action, Subject, scopeSource. '
                .'Got: '.implode(',', $args)
            );
        }

        [$actionArg, $subjectArg, $scopeSource] = $args;

        $action = Action::fromConst($actionArg);
        $subject = Subject::fromConst($subjectArg);
        $scopeId = $this->resolveScopeId($request, $scopeSource);

        Road::assert(Road::can($action, $subject)->in($scopeId));

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
