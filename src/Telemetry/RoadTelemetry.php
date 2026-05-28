<?php

declare(strict_types=1);

namespace B1Road\Laravel\Telemetry;

use B1Road\Laravel\Exceptions\RoadException;

/**
 * Observability hooks for outbound Road API calls. Field shape mirrors
 * `@b1-road/react`'s and `@b1-road/nestjs`'s `TelemetryRequestEvent` so
 * a single sink wires every SDK without translation.
 *
 * Default implementation: `NoopTelemetry`. Integrators bind their own
 * implementation in a service provider to wire e.g. Laravel Pulse, an
 * APM, or a custom log channel.
 */
interface RoadTelemetry
{
    public function onRequest(TelemetryRequestEvent $event): void;

    public function onError(RoadException $error, TelemetryRequestEvent $event): void;
}
