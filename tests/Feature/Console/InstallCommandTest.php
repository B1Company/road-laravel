<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

afterEach(function () {
    File::delete(base_path('.env'));
});

it('appends the Road env keys to .env and is idempotent', function () {
    File::put(base_path('.env'), "APP_NAME=Test\n");

    $this->artisan('road:install', ['--no-interaction' => true])
        ->expectsOutputToContain('Appended')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_API_BASE_URL=https://api.road.b1.app');
    expect($env)->toContain('AUTH_SERVER_ISSUER_URL=');
    expect($env)->toContain('# Road SDK');

    // Re-running appends nothing — the keys are already present.
    $this->artisan('road:install', ['--no-interaction' => true])
        ->expectsOutputToContain('All Road env keys already present')
        ->assertExitCode(0);

    expect(substr_count(File::get(base_path('.env')), 'ROAD_API_BASE_URL='))->toBe(1);
});

it('warns but succeeds when there is no .env to append to', function () {
    File::delete(base_path('.env'));

    $this->artisan('road:install', ['--no-interaction' => true])
        ->expectsOutputToContain('.env not found')
        ->assertExitCode(0);
});

it('the interactive wizard writes the answered values + derives the redirect URI', function () {
    File::put(base_path('.env'), "APP_NAME=Test\nAUTH_SERVER_CLIENT_ID=old-value\n");
    config(['app.url' => 'https://my-app.test']);

    // In a non-TTY test run Laravel Prompts falls back to console questions,
    // which expectsQuestion / expectsConfirmation drive by label.
    $this->artisan('road:install')
        ->expectsQuestion('Road API base URL', 'https://api.road.b1.app')
        ->expectsQuestion('Auth Server issuer URL', 'https://issuer.test')
        ->expectsQuestion('Auth Server client ID', 'client-abc')
        ->expectsQuestion('Auth Server client secret', 'super-secret')
        ->expectsConfirmation('Run `road:doctor` now to verify the wiring?', 'no')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_API_BASE_URL=https://api.road.b1.app');
    expect($env)->toContain('AUTH_SERVER_ISSUER_URL=https://issuer.test');
    expect($env)->toContain('AUTH_SERVER_CLIENT_SECRET=super-secret');
    // An existing key is UPDATED in place, not duplicated.
    expect($env)->toContain('AUTH_SERVER_CLIENT_ID=client-abc');
    expect(substr_count($env, 'AUTH_SERVER_CLIENT_ID='))->toBe(1);
    // The redirect URI is derived from APP_URL, not asked.
    expect($env)->toContain('AUTH_SERVER_REDIRECT_URI=https://my-app.test/auth/road/callback');
});
