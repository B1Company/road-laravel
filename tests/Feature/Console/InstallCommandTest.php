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
    // The stub names the ENVIRONMENT and leaves the URL blank, so config/road.php
    // derives it. It used to stub `https://api.example.com` — a host retired at
    // the plat.eduzz.com cutover — which a blank value can no longer go stale as.
    expect($env)->toContain('ROAD_ENVIRONMENT=sandbox');
    expect($env)->toContain('ROAD_API_BASE_URL=');
    expect($env)->not->toContain('api.example.com');
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
        ->expectsChoice('Which Eduzz Plat environment?', 'sandbox', [
            'sandbox' => 'Sandbox — register, break things, validate here first',
            'production' => 'Production — live data',
            'local' => 'Local — your own stack (you will supply the URL)',
        ])
        ->expectsQuestion('Road API base URL', 'https://api.road-sandbox.b1.app')
        ->expectsQuestion('Auth Server issuer URL', 'https://issuer.test')
        ->expectsQuestion('Auth Server client ID', 'client-abc')
        ->expectsQuestion('Auth Server client secret', 'super-secret')
        ->expectsConfirmation('Run `road:doctor` now to verify the wiring?', 'no')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_API_BASE_URL=https://api.road-sandbox.b1.app');
    expect($env)->toContain('ROAD_ENVIRONMENT=sandbox');
    expect($env)->toContain('AUTH_SERVER_ISSUER_URL=https://issuer.test');
    expect($env)->toContain('AUTH_SERVER_CLIENT_SECRET=super-secret');
    // An existing key is UPDATED in place, not duplicated.
    expect($env)->toContain('AUTH_SERVER_CLIENT_ID=client-abc');
    expect(substr_count($env, 'AUTH_SERVER_CLIENT_ID='))->toBe(1);
    // The redirect URI is derived from APP_URL, not asked.
    expect($env)->toContain('AUTH_SERVER_REDIRECT_URI=https://my-app.test/auth/road/callback');
});

it('writes a secret containing $ and \\ verbatim (no preg backreference mangling)', function () {
    // An existing key whose new value has regex-replacement metacharacters.
    File::put(base_path('.env'), "APP_NAME=Test\nAUTH_SERVER_CLIENT_SECRET=old\n");
    config(['app.url' => 'https://my-app.test']);

    $trickySecret = 'a$1b\2c$0d';

    $this->artisan('road:install')
        ->expectsChoice('Which Eduzz Plat environment?', 'sandbox', [
            'sandbox' => 'Sandbox — register, break things, validate here first',
            'production' => 'Production — live data',
            'local' => 'Local — your own stack (you will supply the URL)',
        ])
        ->expectsQuestion('Road API base URL', 'https://api.road-sandbox.b1.app')
        ->expectsQuestion('Auth Server issuer URL', 'https://issuer.test')
        ->expectsQuestion('Auth Server client ID', 'cid')
        ->expectsQuestion('Auth Server client secret', $trickySecret)
        ->expectsConfirmation('Run `road:doctor` now to verify the wiring?', 'no')
        ->assertExitCode(0);

    // The value survives byte-for-byte — a plain preg_replace would have turned
    // $1/\2/$0 into (empty) backreferences and corrupted the secret.
    expect(File::get(base_path('.env')))->toContain('AUTH_SERVER_CLIENT_SECRET='.$trickySecret);
});

it('defaults the API base URL from the chosen environment', function () {
    // The point of B1-635 at the installer: pick production and the hosted
    // production URL is already in the box. Accepting the default is what an
    // integrator actually does, so the default is what has to be right.
    //
    // `expectsQuestion(..., null)` accepts the offered default rather than
    // typing over it, which is exactly the path under test.
    File::put(base_path('.env'), "APP_NAME=Test\n");
    config(['app.url' => 'https://my-app.test']);

    $this->artisan('road:install')
        ->expectsChoice('Which Eduzz Plat environment?', 'production', [
            'sandbox' => 'Sandbox — register, break things, validate here first',
            'production' => 'Production — live data',
            'local' => 'Local — your own stack (you will supply the URL)',
        ])
        ->expectsQuestion('Road API base URL', '')
        ->expectsQuestion('Auth Server issuer URL', 'https://auth.plat.eduzz.com')
        ->expectsQuestion('Auth Server client ID', 'cid')
        ->expectsQuestion('Auth Server client secret', 'sec')
        ->expectsConfirmation('Run `road:doctor` now to verify the wiring?', 'no')
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_ENVIRONMENT=production');
    expect($env)->toContain('ROAD_API_BASE_URL=https://api.plat.eduzz.com');
    // The counter-assertion that makes the line above mean something: a wizard
    // that ignored the choice would have written the sandbox host here.
    expect($env)->not->toContain('road-sandbox');
});
