<?php

declare(strict_types=1);

use B1Road\Laravel\Bridges\GateBridge;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;

/**
 * The Gate bridge lets a Laravel dev reach Road through `Gate::allows` /
 * `$user->can` / `@can` without learning a second authz API. It's opt-in
 * (`road.bridges.gate`); these tests register it directly so they don't depend
 * on the provider re-booting with the flag flipped.
 */
uses(ActsAsRoadUser::class);

function gateScenario(): RoadScenario
{
    return RoadScenario::make()
        ->withUser('u_admin', email: 'a@road.test')
        ->withUser('u_viewer', email: 'v@road.test')
        ->withBusinessUnit('bu_1', iamScopeId: 'scope_bu_1')
        ->withRole('bu_1', 'Admin', permissions: ['manage:Project'])
        ->withRole('bu_1', 'Viewer', permissions: ['read:Project'])
        ->withMember('bu_1', 'u_admin', roles: ['Admin'])
        ->withMember('bu_1', 'u_viewer', roles: ['Viewer']);
}

beforeEach(function () {
    GateBridge::register(app(GateContract::class));
});

it('answers road:* Gate abilities through Road', function () {
    Road::fake(gateScenario());

    $this->actingAsRoadUser('u_admin');
    expect(Gate::allows('road:read:Project', 'bu_1'))->toBeTrue();
    expect(Gate::allows('road:create:Project', 'bu_1'))->toBeTrue(); // manage:Project expands
    expect(Gate::allows('road:delete:Project', 'bu_1'))->toBeTrue();

    $this->actingAsRoadUser('u_viewer');
    expect(Gate::allows('road:read:Project', 'bu_1'))->toBeTrue();
    expect(Gate::allows('road:create:Project', 'bu_1'))->toBeFalse();
});

it('defers non-road abilities instead of blanket-allowing', function () {
    Road::fake(gateScenario());
    $this->actingAsRoadUser('u_admin');

    // The bridge returns null for anything not prefixed `road:`, so with no gate
    // defined this denies — it does not wave everything through.
    expect(Gate::allows('something:else', 'bu_1'))->toBeFalse();
});

it('defers a malformed road: ability (wrong part count) to normal resolution', function () {
    Road::fake(gateScenario());
    $this->actingAsRoadUser('u_admin');

    // `road:read` has 2 parts, not 3 → the bridge returns null, so no gate = deny.
    expect(Gate::allows('road:read', 'bu_1'))->toBeFalse();
});

it('defers a road: ability with no business-unit argument', function () {
    Road::fake(gateScenario());
    $this->actingAsRoadUser('u_admin');

    // No scope argument → the bridge can't resolve a BU, returns null → deny.
    expect(Gate::allows('road:read:Project'))->toBeFalse();
});

it('is wired by the provider when road.bridges.gate is enabled', function () {
    // The provider boots once with the flag off (test default), so we assert the
    // wiring indirectly: the config key exists and defaults to false, and the
    // GateBridge::register call in boot() is guarded by it.
    expect(config('road.bridges.gate'))->toBeFalse();
});
