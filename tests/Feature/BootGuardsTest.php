<?php

declare(strict_types=1);

use B1Road\Laravel\BootGuards;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * The production-safety boot guards (P6) fail loud at boot on a config that
 * would otherwise 401/500 every request. They run ONLY in production; local/dev
 * boot is never impeded. Mirrors @b1-road/nestjs's normalize() throws.
 */

/** An Application test double reporting a chosen environment. */
function appInEnv(string $env): Application
{
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('environment')->andReturnUsing(
        fn (...$names) => $names === [] ? $env : in_array($env, is_array($names[0] ?? null) ? $names[0] : $names, true)
    );

    return $app;
}

/** A config repo over an array. */
function configOf(array $values): Repository
{
    return new Repository($values);
}

function safeProdConfig(): array
{
    return [
        'road' => [
            // Was api/auth.example.com — hostnames retired at the
            // plat.eduzz.com cutover, and therefore in no environment table, so
            // the cross-environment guard could never have fired on them.
            'environment' => 'production',
            'api' => ['base_url' => 'https://api.plat.eduzz.com'],
            'auth_server' => [
                'issuer_url' => 'https://auth.plat.eduzz.com',
                'client_id' => 'cid',
                'client_secret' => 'sec',
            ],
            'webhooks' => ['enabled' => false, 'secret' => ''],
        ],
        'session' => ['driver' => 'redis'],
    ];
}

it('no-ops outside production even with a broken config', function () {
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'no-scheme-host';
    $bad['session']['driver'] = 'array';

    BootGuards::assert(appInEnv('local'), configOf($bad));
    BootGuards::assert(appInEnv('testing'), configOf($bad));

    expect(true)->toBeTrue(); // reached without throwing
});

it('passes in production with a safe config', function () {
    BootGuards::assert(appInEnv('production'), configOf(safeProdConfig()));
    expect(true)->toBeTrue();
});

it('throws in production on a scheme-less base URL', function () {
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'api.plat.eduzz.com';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'no http(s):// scheme');
});

it('throws in production on a non-persistent session driver', function () {
    $bad = safeProdConfig();
    $bad['session']['driver'] = 'array';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'persistent driver');
});

it('throws in production on missing OIDC credentials', function () {
    $bad = safeProdConfig();
    $bad['road']['auth_server']['client_secret'] = '';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'AUTH_SERVER_CLIENT_SECRET');
});

it('throws in production when webhooks are enabled without a secret', function () {
    $bad = safeProdConfig();
    $bad['road']['webhooks'] = ['enabled' => true, 'secret' => ''];

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'ROAD_WEBHOOK_SECRET');
});

/**
 * The cross-environment guard (B1-635).
 *
 * `config/road.php` now derives the API base from ROAD_ENVIRONMENT, so an app
 * that names 'production' reaches Plat's production API without setting a URL.
 * That removes a boot error which used to stop one specific accident — holding
 * sandbox credentials in production posture — so the accident is now caught on
 * purpose. Mirrors @b1-road/node-core's `assertOneEnvironment`.
 */
it('throws when production posture holds a sandbox issuer', function () {
    $bad = safeProdConfig();
    $bad['road']['auth_server']['issuer_url'] = 'https://auth.road-sandbox.b1.app';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, "AUTH_SERVER_ISSUER_URL ('https://auth.road-sandbox.b1.app') is sandbox's");
});

it('throws when production posture holds a sandbox API base', function () {
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'https://api.road-sandbox.b1.app';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, "ROAD_API_BASE_URL ('https://api.road-sandbox.b1.app') is sandbox's");
});

it('throws when sandbox posture holds a production issuer', function () {
    // The other direction: production credentials pasted into an app that never
    // had ROAD_ENVIRONMENT flipped.
    $bad = safeProdConfig();
    $bad['road']['environment'] = 'sandbox';
    $bad['road']['api']['base_url'] = 'https://api.road-sandbox.b1.app';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, "is production's");
});

it('stays silent on a host it does not recognise', function () {
    // A gateway, a tunnel or a self-hosted Auth Server matches no known origin.
    // A guard that guesses about setups it cannot judge is a guard people
    // disable, so this must reach the end without throwing.
    $own = safeProdConfig();
    $own['road']['api']['base_url'] = 'https://road-gateway.acme.example';
    $own['road']['auth_server']['issuer_url'] = 'https://sso.acme.example';

    BootGuards::assert(appInEnv('production'), configOf($own));

    // Not `expect(true)->toBeTrue()`: assert on the values the guard read, so a
    // guard that silently skipped the whole block would not pass by default.
    expect(B1Road\Laravel\Environments::of('https://road-gateway.acme.example'))->toBeNull()
        ->and(B1Road\Laravel\Environments::of('https://sso.acme.example'))->toBeNull();
});

it('does not mistake a lookalike host for a known environment', function () {
    // `api.plat.eduzz.com.evil.tld` CONTAINS a production hostname and belongs
    // to whoever owns evil.tld. Asserted from SANDBOX posture so a substring
    // match would answer "production", disagree, and refuse a boot that should
    // be allowed — under production posture it would agree and prove nothing.
    $lookalike = safeProdConfig();
    $lookalike['road']['environment'] = 'sandbox';
    $lookalike['road']['api']['base_url'] = 'https://api.road-sandbox.b1.app';
    $lookalike['road']['auth_server']['issuer_url'] = 'https://auth.plat.eduzz.com.evil.tld';

    BootGuards::assert(appInEnv('production'), configOf($lookalike));

    expect(B1Road\Laravel\Environments::of('https://auth.plat.eduzz.com.evil.tld'))->toBeNull();
});

it('checks the Road environment even outside APP_ENV=production', function () {
    // The other guards are production-safety checks: a memory session driver is
    // fine while you develop. Mixing Plat environments never is — APP_ENV=staging
    // with ROAD_ENVIRONMENT=production is exactly the deploy that most needs
    // telling, and the early return used to skip it there.
    $bad = safeProdConfig();
    $bad['road']['auth_server']['issuer_url'] = 'https://auth.road-sandbox.b1.app';

    expect(fn () => BootGuards::assert(appInEnv('staging'), configOf($bad)))
        ->toThrow(RuntimeException::class, "is sandbox's");
});

it('still skips the production-only guards outside production', function () {
    // The counter-case that keeps the test above honest: making the Road-env
    // check unconditional must not drag the rest along. A local dev app with an
    // array session driver and no client secret still boots.
    $dev = safeProdConfig();
    $dev['session']['driver'] = 'array';
    $dev['road']['auth_server']['client_secret'] = '';

    BootGuards::assert(appInEnv('local'), configOf($dev));

    expect(B1Road\Laravel\Environments::of($dev['road']['api']['base_url']))
        ->toBe('production');
});

it('refuses the API URL pasted into the issuer slot', function () {
    // Right environment, wrong KIND of URL. An environment-only check calls this
    // fine and it dies later at OIDC discovery with a 404 naming neither
    // variable.
    $bad = safeProdConfig();
    $bad['road']['auth_server']['issuer_url'] = 'https://api.plat.eduzz.com';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, "is Eduzz Plat's api URL for production, not its Auth Server");
});
