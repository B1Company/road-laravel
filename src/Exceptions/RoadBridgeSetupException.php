<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

/**
 * A Bridge consumer helper cannot run as the app is set up: no service
 * credential (`service_credentials_missing`), no platform id
 * (`platform_id_missing`), or no signed-in person behind the client
 * (`person_required`). A 500, because it is the app's wiring and a 401 would
 * send a signed-in user back to the login page. Mirrors `RoadBridgeSetupError`
 * in `@b1-road/node-core`.
 */
final class RoadBridgeSetupException extends RoadException
{
    public function httpStatus(): int
    {
        return 500;
    }
}
