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
            'api' => ['base_url' => 'https://api.road.b1.app'],
            'auth_server' => [
                'issuer_url' => 'https://auth.road.b1.app',
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
    $bad['road']['api']['base_url'] = 'api.road.b1.app';

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
