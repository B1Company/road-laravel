<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Wraps Laravel's HTTP client to call the Road API. Reads the user's Bearer
 * from RoadContext per call — the indirection lets follow-ups slot
 * retry/idempotency middleware and a service-token store in here without
 * touching call sites.
 */
final class HttpTransport
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
    ) {
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
        $token = $this->context->token();
        if ($token === null || $token === '') {
            throw new RoadAuthnException(
                message: 'No access token available on RoadContext — call Road from a `road`-protected route.',
                errorCode: 'no_token',
            );
        }

        $url = $this->buildUrl($path);
        $timeout = (int) $this->config->get('road.api.timeout', 10);

        $pending = $this->http
            ->withToken($token)
            ->withHeaders([
                'Accept'       => 'application/json',
                'X-Request-Id' => $this->context->requestId(),
            ])
            ->timeout($timeout)
            ->acceptJson();

        try {
            $response = match (strtoupper($method)) {
                'GET'    => $pending->get($url, $query ?? []),
                'POST'   => $pending->asJson()->post($url, $body ?? []),
                'PATCH'  => $pending->asJson()->patch($url, $body ?? []),
                'PUT'    => $pending->asJson()->put($url, $body ?? []),
                'DELETE' => $pending->asJson()->delete($url, $body ?? []),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: $method"),
            };
        } catch (Throwable $e) {
            throw ErrorMapper::map(0, ['error' => ['code' => 'network_error', 'message' => $e->getMessage()]]);
        }

        if (! $response->successful()) {
            throw ErrorMapper::map($response->status(), $this->parseBody($response));
        }

        return $this->parseBody($response) ?? [];
    }

    private function buildUrl(string $path): string
    {
        $base = (string) $this->config->get('road.api.base_url', '');
        if ($base === '') {
            throw new \RuntimeException('road.api.base_url is not configured.');
        }

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    /** @return array<string,mixed>|null */
    private function parseBody(Response $response): ?array
    {
        $body = $response->body();
        if ($body === '') {
            return null;
        }

        $parsed = json_decode($body, associative: true);

        return is_array($parsed) ? $parsed : null;
    }
}
