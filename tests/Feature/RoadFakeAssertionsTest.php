<?php

declare(strict_types=1);

use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use PHPUnit\Framework\AssertionFailedError;

uses(ActsAsRoadUser::class);

it('fails assertCalled clearly when the call was never made', function () {
    $fake = Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    expect(fn () => $fake->assertCalled('GET', '/never/called'))
        ->toThrow(AssertionFailedError::class);
});

it('fails assertNothingCalled once a call has been recorded', function () {
    $fake = Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    app(RoadClient::class)->businessUnits('bu_1')->fetch();

    expect(fn () => $fake->assertNothingCalled())
        ->toThrow(AssertionFailedError::class);
});

it('fails assertCallCount on a mismatch and passes on a match', function () {
    $fake = Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    app(RoadClient::class)->businessUnits('bu_1')->fetch();

    expect(fn () => $fake->assertCallCount(99))->toThrow(AssertionFailedError::class);

    $fake->assertCallCount(1);
    $fake->assertCalled('GET', '/organization/business-units/bu_1');
});
