<?php

declare(strict_types=1);

use B1Road\Laravel\Environments;
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
    // The answer equalled the hosted default, so the URL is NOT written: an
    // explicit ROAD_API_BASE_URL would win over the derived one and silently
    // defeat a later ROAD_ENVIRONMENT flip.
    expect($env)->toContain('ROAD_ENVIRONMENT=sandbox');
    expect($env)->toContain('ROAD_API_BASE_URL=');
    expect($env)->not->toContain('ROAD_API_BASE_URL=https://');
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
    // Blank on purpose. config/road.php derives the URL from the environment,
    // so writing it here would pin the app to production even after someone
    // flipped ROAD_ENVIRONMENT back — and pin it to SANDBOX in the far more
    // common direction, which is how the wizard used to defeat its own switch.
    expect($env)->toContain('ROAD_API_BASE_URL=');
    expect($env)->not->toContain('ROAD_API_BASE_URL=https://');
    expect($env)->not->toContain('road-sandbox');

    // And the environment that .env names does resolve to the production API.
    expect(Environments::apiUrl('production'))
        ->toBe('https://api.plat.eduzz.com');
});

it('writes the URL when the answer is NOT the hosted default', function () {
    // The escape hatch: an app behind its own gateway in front of Plat. Here an
    // explicit ROAD_API_BASE_URL is the point, and it must survive.
    File::put(base_path('.env'), "APP_NAME=Test\n");
    config(['app.url' => 'https://my-app.test']);

    $this->artisan('road:install')
        ->expectsChoice('Which Eduzz Plat environment?', 'production', [
            'sandbox' => 'Sandbox — register, break things, validate here first',
            'production' => 'Production — live data',
            'local' => 'Local — your own stack (you will supply the URL)',
        ])
        ->expectsQuestion('Road API base URL', 'https://road-gateway.acme.example')
        ->expectsQuestion('Auth Server issuer URL', 'https://auth.plat.eduzz.com')
        ->expectsQuestion('Auth Server client ID', 'cid')
        ->expectsQuestion('Auth Server client secret', 'sec')
        ->expectsConfirmation('Run `road:doctor` now to verify the wiring?', 'no')
        ->assertExitCode(0);

    expect(File::get(base_path('.env')))
        ->toContain('ROAD_API_BASE_URL=https://road-gateway.acme.example');
});

it('does not clobber an existing ROAD_ENV with a sandbox default', function () {
    // .env already says production through the alias. Appending
    // ROAD_ENVIRONMENT=sandbox beside it would WIN — config/road.php gives the
    // canonical name precedence — and silently move a production install to
    // sandbox, with the blank ROAD_API_BASE_URL then resolving to the sandbox
    // API. (CodeRabbit, #613.)
    File::put(base_path('.env'), "APP_NAME=Test\nROAD_ENV=production\n");

    $this->artisan('road:install', ['--no-interaction' => true])
        ->assertExitCode(0);

    $env = File::get(base_path('.env'));
    expect($env)->toContain('ROAD_ENV=production');
    expect($env)->not->toContain('ROAD_ENVIRONMENT=');
});

it('still stubs the environment when .env names neither key', function () {
    // The pairing that gives the test above teeth: a fresh .env must still get
    // an environment, or the stub stops doing its job entirely.
    File::put(base_path('.env'), "APP_NAME=Test\n");

    $this->artisan('road:install', ['--no-interaction' => true])
        ->assertExitCode(0);

    expect(File::get(base_path('.env')))->toContain('ROAD_ENVIRONMENT=sandbox');
});
