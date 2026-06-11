<?php

declare(strict_types=1);

namespace B1Road\Laravel\Tests;

use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\RoadServiceProvider;
use Inertia\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Testbench disables package auto-discovery, so the SDK's own
            // dependencies must be registered explicitly. Without
            // LaravelDataServiceProvider, config('data') is null and the
            // spatie/laravel-data DTOs blow up on hydration.
            LaravelDataServiceProvider::class,
            // inertiajs/inertia-laravel is a dev dependency so the
            // Inertia auto-mount + shared-props path is exercised against
            // the real package, not a stub.
            ServiceProvider::class,
            RoadServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Road' => Road::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // EncryptCookies (pulled in by the `web` group on the auth
        // routes) requires an application key.
        $app['config']->set('app.key', 'base64:AckfSECXAvnyTQViQF/IST3yMcgGW36C5kP+JTxRRMc=');
        $app['config']->set('road.api.base_url', 'https://api.road.test');
        $app['config']->set('road.api.version', 'alpha');
        // Retries still run in tests (so the loop is exercised), but with no
        // real backoff wait. The dedicated retry tests set a delay + Sleep::fake().
        $app['config']->set('road.api.retry.base_delay_ms', 0);
        $app['config']->set('road.auth_server.issuer_url', 'https://auth.test');
        $app['config']->set('road.auth_server.audience', 'road-api');
        $app['config']->set('road.auth_server.client_id', 'test-client');
        $app['config']->set('road.auth_server.client_secret', 'test-secret');
        $app['config']->set('road.auth_server.redirect_uri', 'https://app.test/auth/road/callback');
    }
}
