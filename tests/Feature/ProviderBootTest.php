<?php

declare(strict_types=1);

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\RoadManager;
use Illuminate\Contracts\Console\Kernel;

it('boots the service provider and resolves the RoadManager singleton', function () {
    expect($this->app->bound(RoadManager::class))->toBeTrue();
    expect($this->app->make(RoadManager::class))->toBeInstanceOf(RoadManager::class);
});

it('returns null user and false isAuthenticated when no session is resolved', function () {
    expect(Road::user())->toBeNull();
    expect(Road::userId())->toBeNull();
    expect(Road::token())->toBeNull();
    expect(Road::isAuthenticated())->toBeFalse();
});

it('exposes a request-scoped RoadContext with a generated requestId', function () {
    expect(Road::context())->toBeInstanceOf(RoadContext::class);

    $first = Road::requestId();
    expect($first)->toBeString()->not->toBeEmpty();
    expect(Road::requestId())->toBe($first); // stable within the same request
});

it('publishes the road config under the road key', function () {
    expect(config('road.api.version'))->toBe('alpha');
    expect(config('road.proxy.prefix'))->toBe('road-api');
    expect(config('road.token_store'))->toBe('session');
});

it('registers the artisan commands so Artisan::call works outside the CLI', function () {
    // The commands must be registered unconditionally — not only when
    // runningInConsole() — so an in-app health panel can run `road:doctor` over
    // HTTP (Beacon's /demo/doctor did a 500 "command does not exist" when this
    // was CLI-gated). Assert each is on the Artisan kernel.
    $registered = array_keys(app(Kernel::class)->all());
    expect($registered)->toContain('road:doctor');
    expect($registered)->toContain('road:install');
    expect($registered)->toContain('road:whoami');
    expect($registered)->toContain('road:generate-dtos');
});
