<?php

declare(strict_types=1);

namespace B1Road\Laravel\Facades;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Can;
use B1Road\Laravel\Authorization\CanBatch;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\RoadManager;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Support\Facades\Facade;

/**
 * @method static RoadUser|null user()
 * @method static string|null userId()
 * @method static string|null token()
 * @method static bool isAuthenticated()
 * @method static RoadContext context()
 * @method static string requestId()
 * @method static RoadClient client()
 * @method static Can can(Action $action, Subject $subject)
 * @method static CanBatch canMany(array $checks)
 * @method static void assert(Can $check)
 * @method static RoadFakeAssertions fake(RoadScenario $scenario)
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
