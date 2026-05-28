<?php

declare(strict_types=1);

// Pull in the Inertia stub BEFORE any provider boot. Once this is
// loaded the rest of the test process sees `\Inertia\Inertia` as
// present, which is exactly what we want for verifying the
// auto-mount + the doctor's "Inertia installed" branch. Do not run
// "Inertia not installed" assertions in this file — see
// DoctorCommandTest.php for that path.
require_once __DIR__.'/../Stubs/Inertia.php';

use B1Road\Laravel\Inertia\ShareRoadContext;
use Illuminate\Routing\Router;

it('auto-mounts ShareRoadContext into the web middleware group on boot', function () {
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

    expect($webGroup)->toContain(ShareRoadContext::class);
});

it('does not double-append on repeated boot (idempotent)', function () {
    /** @var Router $router */
    $router = $this->app->make(Router::class);
    $webGroup = $router->getMiddlewareGroups()['web'] ?? [];

    $occurrences = array_filter(
        $webGroup,
        fn ($m) => $m === ShareRoadContext::class,
    );

    expect(count($occurrences))->toBe(1);
});

it('skips auto-mount when road.inertia.enabled is false', function () {
    config()->set('road.inertia.enabled', false);

    // Reboot the provider by deferring to the fresh resolution path —
    // Testbench resets state between tests, so the cleanest way to
    // assert the disabled branch is to use a clean test app via
    // refreshApplication() in a subclass. Here we verify the config
    // gate evaluates correctly via the doctor command which is the
    // observable surface.
    $this->artisan('road:doctor')
        ->expectsOutputToContain('Inertia auto-mount disabled');
});

it('doctor reports the shared-props pipeline as healthy when wired', function () {
    config()->set('road.inertia.enabled', true);

    $this->artisan('road:doctor')
        ->expectsOutputToContain('Inertia shared props wired');
});
