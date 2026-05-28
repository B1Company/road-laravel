<?php

declare(strict_types=1);

use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Support\Facades\Route;

uses(ActsAsRoadUser::class);

it('lets a road-protected route resolve Road::user() and Road::client()->me() via the fake harness', function () {
    $scenario = RoadScenario::make()
        ->withUser('u_owner', name: 'Eduardo', email: 'eduardo@b1.app')
        ->withBusinessUnit('bu_1', name: 'B1', slug: 'b1')
        ->withMember('bu_1', 'u_owner');

    $fake = Road::fake($scenario);
    $this->actingAsRoadUser('u_owner', 'eduardo@b1.app', 'Eduardo');

    Route::middleware('road')->get('/whoami', fn () => Road::user()?->toArray());
    Route::middleware('road')->get('/me-via-client', fn () => Road::client()->me()->get()->toArray());
    Route::middleware('road')->get('/my-bus', fn () => [
        'count' => Road::client()->me()->businessUnits()->memberships->count(),
    ]);

    $this->getJson('/whoami')
        ->assertOk()
        ->assertJsonPath('id', 'u_owner')
        ->assertJsonPath('email', 'eduardo@b1.app');

    $this->getJson('/me-via-client')
        ->assertOk()
        ->assertJsonPath('id', 'u_owner')
        ->assertJsonPath('email', 'eduardo@b1.app');

    $this->getJson('/my-bus')->assertOk()->assertJsonPath('count', 1);

    $fake->assertCalled('GET', '/iam/identity/me');
    $fake->assertCalled('GET', '/me/business-units');
});

it('returns 401 JSON on a road-protected api route without a session', function () {
    Route::middleware('road')->get('/protected', fn () => ['ok' => true]);

    $this->getJson('/protected')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('redirects html visitors to the OIDC login URL', function () {
    Route::middleware('road')->get('/private-html', fn () => 'should not render');

    $response = $this->get('/private-html');
    $response->assertStatus(302);
    $location = (string) $response->headers->get('Location');
    expect($location)->toContain('/auth/road/login');
    expect($location)->toContain('intended=');
});

it('exposes BFF proxy at /road-api/* with the session Bearer attached', function () {
    Road::fake(
        RoadScenario::make()
            ->withUser('u_proxy', name: 'Proxy User', email: 'p@b1.app')
            ->withBusinessUnit('bu_proxy', name: 'PB1', slug: 'pb1')
            ->withMember('bu_proxy', 'u_proxy')
    );
    $this->actingAsRoadUser('u_proxy', 'p@b1.app', 'Proxy User');

    \Illuminate\Support\Facades\Http::fake([
        'api.road.test/organization/business-units/bu_proxy' => \Illuminate\Support\Facades\Http::response(
            ['data' => ['id' => 'bu_proxy', 'name' => 'PB1']],
            200,
        ),
    ]);

    $response = $this->getJson('/road-api/organization/business-units/bu_proxy');
    $response->assertOk()->assertJsonPath('data.id', 'bu_proxy');

    \Illuminate\Support\Facades\Http::assertSent(function (\Illuminate\Http\Client\Request $req) {
        return $req->hasHeader('Authorization', 'Bearer fake-u_proxy');
    });
});

it('rejects /road-api paths outside the proxy allowlist', function () {
    Road::fake(RoadScenario::make()->withUser('u', email: 'u@u.com'));
    $this->actingAsRoadUser('u');

    $this->getJson('/road-api/something/private')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
