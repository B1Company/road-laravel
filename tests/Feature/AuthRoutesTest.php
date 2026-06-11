<?php

declare(strict_types=1);

use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Support\Facades\Route;

uses(ActsAsRoadUser::class);

it('GET /road/whoami returns the authenticated Road user', function () {
    Road::fake(RoadScenario::make()->withUser('u_owner', email: 'o@b1.app', name: 'Owner')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u_owner', email: 'o@b1.app', name: 'Owner');

    $this->getJson('/road/whoami')
        ->assertOk()
        ->assertJsonPath('user.id', 'u_owner')
        ->assertJsonPath('user.email', 'o@b1.app');
});

it('resolves the auth("road") guard from the Road context', function () {
    // The SDK registers the `road` driver + provider; the host app wires the
    // guard (documented in the README).
    config([
        'auth.guards.road' => ['driver' => 'road', 'provider' => 'road'],
        'auth.providers.road' => ['driver' => 'road'],
    ]);

    Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    expect(auth('road')->check())->toBeTrue();
    expect(auth('road')->guest())->toBeFalse();
    expect(auth('road')->id())->toBe('u');
    expect(auth('road')->user()?->id)->toBe('u');
});

it('the road.optional middleware passes through when there is no user', function () {
    Route::middleware('road.optional')->get('/maybe', fn () => ['user' => Road::userId()]);

    $this->getJson('/maybe')->assertOk()->assertJsonPath('user', null);
});

it('the road.optional middleware resolves the user when present', function () {
    Route::middleware('road.optional')->get('/maybe', fn () => ['user' => Road::userId()]);

    Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    $this->getJson('/maybe')->assertOk()->assertJsonPath('user', 'u');
});
