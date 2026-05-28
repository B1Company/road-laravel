<?php

declare(strict_types=1);

namespace B1Road\Laravel\Telemetry;

final readonly class TelemetryRequestEvent
{
    public function __construct(
        public string $method,
        public string $path,
        public int $status,
        public float $durationMs,
        public ?string $requestId,
        public ?string $traceId,
        public int $attempts,
    ) {}
}
