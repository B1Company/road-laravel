<?php

declare(strict_types=1);

namespace B1Road\Laravel\Bridges;

use B1Road\Laravel\Facades\Road;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Makes Road answer Laravel's native authorization. Any ability of the form
 * `road:{action}:{Subject}` — with the business-unit (scope) id as the first
 * argument — delegates to Road's engine, so a Laravel dev reaches Road through
 * the idioms they already know:
 *
 *   Gate::allows('road:create:Project', $buId)
 *   $request->user()->can('road:read:Project', $buId)
 *   Blade `@can` on a `road:read:Project` ability (first arg = the BU id)
 *
 * Opt-in via `road.bridges.gate` (default off) — a host app that never touches
 * Laravel's Gate for Road shouldn't pay for a `Gate::before` hook it doesn't use.
 * Returning null for any other ability lets normal Gate/Policy resolution
 * continue, so this composes with the host app's own gates.
 */
final class GateBridge
{
    /**
     * Register the `Gate::before` hook. Idempotent to call once at boot.
     */
    public static function register(Gate $gate): void
    {
        $gate->before(function (mixed $user, string $ability, array $arguments = []): ?bool {
            if (! str_starts_with($ability, 'road:')) {
                return null;
            }

            $parts = explode(':', $ability);
            if (count($parts) !== 3) {
                return null;
            }

            [, $action, $subject] = $parts;
            if ($action === '' || $subject === '') {
                return null;
            }

            $buId = $arguments[0] ?? null;
            if (! is_string($buId) || $buId === '') {
                return null;
            }

            return Road::can($action, $subject)->in($buId)->check();
        });
    }
}
