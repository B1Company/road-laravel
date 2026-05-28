<?php

declare(strict_types=1);

namespace B1Road\Laravel\Telemetry;

use B1Road\Laravel\Exceptions\RoadException;

/**
 * Default RoadTelemetry implementation: ignores every event.
 */
final class NoopTelemetry implements RoadTelemetry
{
    public function onRequest(TelemetryRequestEvent $event): void {}

    public function onError(RoadException $error, TelemetryRequestEvent $event): void {}
}
