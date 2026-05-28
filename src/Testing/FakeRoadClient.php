<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;

/**
 * RoadClient subclass that routes calls to InMemoryBackend instead of HTTP.
 *
 * It still composes a HttpTransport (the resources type-hint it) but the
 * transport's `request()` is overridden by FakeHttpTransport to consult
 * the backend. The integrator-facing surface is unchanged — Pest tests
 * call `Road::client()->me()->get()` and get the seeded scenario data.
 */
final class FakeRoadClient extends RoadClient
{
    public function __construct(
        FakeHttpTransport $http,
        public readonly InMemoryBackend $backend,
    ) {
        parent::__construct($http);
    }
}

/**
 * @internal HttpTransport replacement used inside FakeRoadClient.
 */
final class FakeHttpTransport extends HttpTransport
{
    public function __construct(
        private readonly InMemoryBackend $backend,
        private readonly RoadContext $context,
    ) {
        // We never call the parent's network code, but the parent
        // constructor expects three deps. We pass dummies — they're
        // unused because we override `request()` entirely below.
        parent::__construct(
            new \Illuminate\Http\Client\Factory(),
            $context,
            new \Illuminate\Config\Repository(['road' => ['api' => ['base_url' => 'http://fake', 'timeout' => 10]]]),
        );
    }

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
        $userId = $this->context->user()?->id;

        $result = $this->backend->handle($method, $path, $body, $userId);

        return $result['body'];
    }
}
