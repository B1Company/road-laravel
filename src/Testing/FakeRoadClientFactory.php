<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;

/**
 * Builds a test-mode RoadClient pointed at a FakeHttpTransport. Kept
 * as a thin factory so RoadManager doesn't reach into Testing
 * internals at runtime.
 */
final class FakeRoadClientFactory
{
    public function build(InMemoryBackend $backend, RoadContext $context): RoadClient
    {
        return new RoadClient(new FakeHttpTransport($backend, $context));
    }
}
