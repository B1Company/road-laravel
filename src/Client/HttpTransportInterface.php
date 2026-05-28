<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

/**
 * The seam Road resources sit on. The real implementation
 * (`HttpTransport`) wraps Laravel's Http facade; the test
 * implementation (`Testing\FakeHttpTransport`) routes through an
 * in-memory backend. Resources never see fetch or Guzzle.
 *
 * Mutating methods auto-attach an `Idempotency-Key` header in the real
 * implementation; the fake doesn't bother (every test is its own
 * isolated graph).
 */
interface HttpTransportInterface
{
    /**
     * Issue a request to the Road API and return the JSON-decoded body.
     *
     * @param  array<string,mixed>|null  $body
     * @param  array<string,scalar|null>|null  $query
     * @return array<string,mixed>
     */
    public function request(
        string $method,
        string $path,
        ?array $body = null,
        ?array $query = null,
    ): array;
}
