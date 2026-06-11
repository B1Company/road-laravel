<?php

declare(strict_types=1);

use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\RoadManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/**
 * Pins the integrator-facing public surface — middleware aliases, config keys,
 * Artisan commands, and facade methods — so an accidental rename or removal
 * fails a test instead of an integrator's app.
 */
it('registers every documented middleware alias', function () {
    /** @var array<string,class-string> $aliases */
    $aliases = Route::getMiddleware();

    foreach ([
        'road', 'road.optional', 'road.errors', 'road.inertia',
        'road.permission', 'road.permission.attribute', 'road.webhook',
    ] as $alias) {
        expect($aliases)->toHaveKey($alias);
    }
});

it('exposes every documented config section', function () {
    expect(config('road.api.base_url'))->not->toBeNull();

    foreach ([
        'road.api.retry', 'road.auth_server', 'road.proxy',
        'road.service', 'road.webhooks', 'road.inertia', 'road.debug',
    ] as $key) {
        expect(config($key))->toBeArray();
    }
});

it('registers the Artisan commands', function () {
    $commands = array_keys(Artisan::all());

    foreach (['road:install', 'road:doctor', 'road:whoami', 'road:generate-dtos'] as $command) {
        expect($commands)->toContain($command);
    }
});

it('resolves the Road facade to the manager with its documented methods', function () {
    expect(Road::getFacadeRoot())->toBeInstanceOf(RoadManager::class);

    foreach ([
        'user', 'userId', 'token', 'isAuthenticated', 'context', 'requestId',
        'client', 'can', 'canMany', 'assert', 'asService', 'fake',
    ] as $method) {
        expect(method_exists(RoadManager::class, $method))->toBeTrue("RoadManager::{$method}() is missing");
    }
});
