<?php

declare(strict_types=1);

namespace B1Road\Laravel\Inertia;

use B1Road\Laravel\Context\RoadContext;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Injects `props.road` into Inertia shared props for every request that
 * resolves an Inertia view.
 *
 *   props.road = {
 *     apiBaseUrl,              // '/road-api' by default
 *     user,                    // { id, name, email, avatarUrl } | null
 *     currentBusinessUnitId,
 *     loginUrl,                // '/auth/road/login'
 *     logoutUrl,               // '/auth/road/logout'
 *   }
 *
 * No JWT, no refresh token, no permissions — permissions are not shared by
 * default (they'd grow unbounded; React fetches via useMyPermissions on demand).
 *
 * Registration: add the `road.inertia` middleware alias to the Inertia
 * middleware group, OR include `\B1Road\Laravel\Inertia\ShareRoadContext::class`
 * directly. The middleware no-ops if inertiajs/inertia-laravel isn't installed.
 */
final class ShareRoadContext
{
    public function __construct(
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('road.inertia.enabled', true) && class_exists(\Inertia\Inertia::class)) {
            \Inertia\Inertia::share('road', fn () => $this->props($request));
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /** @return array<string,mixed> */
    private function props(Request $request): array
    {
        $user = $this->context->user();
        $proxyPrefix = (string) $this->config->get('road.proxy.prefix', 'road-api');

        return [
            'apiBaseUrl'            => '/'.ltrim($proxyPrefix, '/'),
            'user'                  => $user?->toArray(),
            'currentBusinessUnitId' => $request->session()->get('road.current_business_unit_id'),
            'loginUrl'              => '/auth/road/login',
            'logoutUrl'             => '/auth/road/logout',
        ];
    }
}
