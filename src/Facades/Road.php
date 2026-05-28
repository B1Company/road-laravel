<?php

declare(strict_types=1);

namespace B1Road\Laravel\Facades;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\RoadManager;
use B1Road\Laravel\Testing\InMemoryBackend;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RoadUser|null      user()
 * @method static string|null        userId()
 * @method static string|null        token()
 * @method static bool               isAuthenticated()
 * @method static RoadContext        context()
 * @method static string             requestId()
 * @method static RoadClient         client()
 * @method static InMemoryBackend    fake(RoadScenario $scenario)
 * @method static void               assertCalled(string $method, string $path)
 *
 * @see RoadManager
 */
final class Road extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RoadManager::class;
    }
}
