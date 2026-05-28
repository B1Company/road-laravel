<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Context\RoadContext;

/**
 * Test-only HttpTransport that delegates to an InMemoryBackend instead
 * of dialing the network. Implements `HttpTransportInterface` as an
 * equal citizen with the real `HttpTransport` — no inheritance
 * gymnastics around private readonly state.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly InMemoryBackend $backend,
        private readonly RoadContext $context,
    ) {}

    /**
     * @param  array<string,mixed>|null  $body
     * @param  array<string,scalar|null>|null  $query
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?array $query = null,
    ): array {
        $result = $this->backend->handle(
            method: $method,
            path: $path,
            body: $body,
            userId: $this->context->user()?->id,
        );

        return $result['body'];
    }
}
