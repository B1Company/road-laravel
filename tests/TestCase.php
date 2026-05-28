<?php

declare(strict_types=1);

namespace B1Road\Laravel\Tests;

use B1Road\Laravel\RoadServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            RoadServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Road' => \B1Road\Laravel\Facades\Road::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('road.api.base_url', 'https://api.road.test');
        $app['config']->set('road.api.version', 'alpha');
        $app['config']->set('road.auth_server.issuer_url', 'https://auth.test');
        $app['config']->set('road.auth_server.audience', 'road-api');
        $app['config']->set('road.auth_server.client_id', 'test-client');
        $app['config']->set('road.auth_server.client_secret', 'test-secret');
        $app['config']->set('road.auth_server.redirect_uri', 'https://app.test/auth/road/callback');
    }
}
