<?php

declare(strict_types=1);

use B1Road\Laravel\Inertia\ShareRoadContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/*
 * Inertia integration is exercised against the REAL
 * inertiajs/inertia-laravel package (a dev dependency registered in
 * TestCase), not a stub. The "Inertia not installed" branch of the
 * doctor check is intentionally not unit-tested here: with the package
 * present process-wide there is no honest way to simulate its absence
 * without process isolation, and a load-order trick would be a false
 * positive. That branch is a one-line defensive guard covered by review.
 */

// Keep the doctor's network probes (Road API reachability, Auth Server
// discovery, JWKS, clock skew) off the wire so these tests assert the
// Inertia branch deterministically instead of flaking on DNS.
beforeEach(function () {
    Http::fake(['*' => Http::response([], 500)]);
});

it('auto-mounts ShareRoadContext into the web middleware group on boot', function () {
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

    expect($webGroup)->toContain(ShareRoadContext::class);
});

it('appends ShareRoadContext exactly once (idempotent across boots)', function () {
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

    $occurrences = array_filter($webGroup, fn ($m) => $m === ShareRoadContext::class);

    expect(count($occurrences))->toBe(1);
});

it('shares props.road via the Inertia facade when the middleware runs', function () {
    $middleware = $this->app->make(ShareRoadContext::class);

    // The middleware is auto-mounted into the `web` group, so it always
    // runs after StartSession — attach a session to mirror that.
    $request = Request::create('/dashboard', 'GET');
    $request->setLaravelSession($this->app->make('session')->driver());

    $middleware->handle($request, fn ($req) => response('ok'));

    // The real Inertia facade records the shared closure under `road`.
    $shared = Inertia::getShared('road');
    expect($shared)->not->toBeNull();

    $props = is_callable($shared) ? $shared() : $shared;
    expect($props)->toBeArray()
        ->toHaveKeys(['apiBaseUrl', 'user', 'currentBusinessUnitId', 'loginUrl', 'logoutUrl']);
    expect($props['apiBaseUrl'])->toBe('/road-api');
    expect($props['loginUrl'])->toBe('/auth/road/login');
});

it('doctor reports the shared-props pipeline as healthy when wired', function () {
    $this->artisan('road:doctor')
        ->expectsOutputToContain('Inertia shared props wired');
});

it('doctor warns when Inertia auto-mount is disabled by config', function () {
    config()->set('road.inertia.enabled', false);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Inertia auto-mount disabled');
});

it('doctor fails when ShareRoadContext is missing from the web group', function () {
    // Simulate a host app whose custom kernel or bootstrap stripped the
    // middleware after auto-mount. The doctor must catch it loudly.
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $group = array_values(array_filter(
        $router->getMiddlewareGroups()['web'] ?? [],
        fn ($m) => $m !== ShareRoadContext::class,
    ));
    $router->middlewareGroup('web', $group);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('ShareRoadContext middleware is NOT in the `web` middleware group');
});
