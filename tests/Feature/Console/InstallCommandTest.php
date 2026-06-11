<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(base_path('.env'));
});

it('appends the Road env keys to .env and is idempotent', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");

    $this->artisan('road:install')
        ->expectsOutputToContain('Appended')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_API_BASE_URL=https://api.road.b1.app');
    expect($env)->toContain('AUTH_SERVER_ISSUER_URL=');
    expect($env)->toContain('# Road SDK');

    // Re-running appends nothing — the keys are already present.
    $this->artisan('road:install')
        ->expectsOutputToContain('All Road env keys already present')
        ->assertExitCode(0);

    expect(substr_count(File::get(base_path('.env')), 'ROAD_API_BASE_URL='))->toBe(1);
});

it('warns but succeeds when there is no .env to append to', function () {
    File::delete(base_path('.env'));

    $this->artisan('road:install')
        ->expectsOutputToContain('.env not found')
        ->assertExitCode(0);
});
