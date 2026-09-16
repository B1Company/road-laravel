<?php

declare(strict_types=1);

use B1Road\Laravel\BootGuards;
use B1Road\Laravel\Environments;
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

it('no-ops outside production for the production-only guards', function () {
    // An array session driver and a missing client secret are fine while you
    // develop, and these guards stay gated on APP_ENV.
    //
    // The scheme check used to be in this list and is NOT any more: a
    // scheme-less base URL is wrong in every environment, and leaving it to
    // production meant `ROAD_API_BASE_URL=localhost:3000` passed boot and then
    // failed at the first request with a RoadNetworkException naming nothing.
    // It now throws everywhere, like @b1-road/node-core already did.
    $bad = safeProdConfig();
    $bad['session']['driver'] = 'array';
    $bad['road']['auth_server']['client_secret'] = '';

    BootGuards::assert(appInEnv('local'), configOf($bad));
    BootGuards::assert(appInEnv('testing'), configOf($bad));

    // Not `expect(true)->toBeTrue()`: assert on a value the guard read, so a
    // version that skipped the whole method could not pass by default.
    expect($bad['session']['driver'])->toBe('array');
});

it('rejects a scheme-less API URL in every app environment', function () {
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'localhost:3000';

    expect(fn () => BootGuards::assert(appInEnv('local'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'has no http(s):// scheme');
});

it('rejects a ROAD_ENVIRONMENT nobody supports', function () {
    // A typo resolves to no hosted URL, which the missing-URL guard usually
    // catches — but not when both URLs are custom origins, and then it boots.
    $bad = safeProdConfig();
    $bad['road']['environment'] = 'prodution';
    $bad['road']['api']['base_url'] = 'https://road-gateway.acme.example';
    $bad['road']['auth_server']['issuer_url'] = 'https://sso.acme.example';

    expect(fn () => BootGuards::assert(appInEnv('local'), configOf($bad)))
        ->toThrow(RuntimeException::class, "ROAD_ENVIRONMENT is 'prodution'");
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
    expect(Environments::of('https://road-gateway.acme.example'))->toBeNull()
        ->and(Environments::of('https://sso.acme.example'))->toBeNull();
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

    expect(Environments::of('https://auth.plat.eduzz.com.evil.tld'))->toBeNull();
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

    expect(Environments::of($dev['road']['api']['base_url']))
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

it('refuses the Auth Server URL pasted into the API slot', function () {
    // The mirror of the issuer check, missing until CodeRabbit flagged it on
    // #613: an Auth Server URL in the API slot makes every proxied request 404
    // against a host that has no /api/alpha.
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'https://auth.plat.eduzz.com';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, "is Eduzz Plat's auth_server URL for production, not its API");
});

it('refuses a local environment with no API URL', function () {
    // `local` is a valid ROAD_ENVIRONMENT that Plat does not host, so
    // Environments::apiUrl() returns null and config/road.php resolves to ''.
    // The scheme check skips an empty value, so the app used to boot and fail on
    // its first request instead. (CodeRabbit, #613.)
    $bad = safeProdConfig();
    $bad['road']['environment'] = 'local';
    $bad['road']['api']['base_url'] = '';
    $bad['road']['auth_server']['issuer_url'] = 'http://localhost:8080';

    expect(fn () => BootGuards::assert(appInEnv('local'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'no Road API base URL');
});

it('still boots a local environment that supplies its own API URL', function () {
    // The counter-case: `local` is legitimate, it just has to say where.
    $ok = safeProdConfig();
    $ok['road']['environment'] = 'local';
    $ok['road']['api']['base_url'] = 'http://localhost:3000';
    $ok['road']['auth_server']['issuer_url'] = 'http://localhost:8080';

    BootGuards::assert(appInEnv('local'), configOf($ok));

    expect(Environments::apiUrl('local'))->toBeNull();
});

it('refuses a hosted Road API reached over plaintext http', function () {
    // It used to pass as an unknown custom origin: origins were compared whole,
    // so a real Road host over http matched nothing, and HttpTransport and
    // ProxyController then sent bearer tokens in the clear. (CodeRabbit, #615.)
    $bad = safeProdConfig();
    $bad['road']['api']['base_url'] = 'http://api.plat.eduzz.com';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'reached over plaintext http');
});

it('refuses a hosted Auth Server reached over plaintext http', function () {
    $bad = safeProdConfig();
    $bad['road']['auth_server']['issuer_url'] = 'http://auth.plat.eduzz.com';

    expect(fn () => BootGuards::assert(appInEnv('production'), configOf($bad)))
        ->toThrow(RuntimeException::class, 'reached over plaintext http');
});

it('still allows a plaintext host that is not Plat\'s', function () {
    // http://localhost is how everyone develops, and it is nobody's hosted
    // surface — the rule has to stay that narrow.
    $ok = safeProdConfig();
    $ok['road']['environment'] = 'local';
    $ok['road']['api']['base_url'] = 'http://localhost:3000';
    $ok['road']['auth_server']['issuer_url'] = 'http://localhost:8080';

    BootGuards::assert(appInEnv('local'), configOf($ok));

    expect(Environments::surfaceOf('http://localhost:3000'))->toBeNull();
});
