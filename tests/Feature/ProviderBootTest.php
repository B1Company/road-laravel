<?php

declare(strict_types=1);

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\RoadManager;

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
