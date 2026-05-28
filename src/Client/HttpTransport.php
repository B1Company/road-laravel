<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Telemetry\TelemetryRequestEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Wraps Laravel's HTTP client to call the Road API. Reads the user's Bearer
 * from RoadContext per call — the indirection lets follow-ups slot a
 * service-token store in here without touching call sites.
 */
final class HttpTransport implements HttpTransportInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
        private readonly RoadTelemetry $telemetry,
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
        $upperMethod = strtoupper($method);

        $pending = $this->http
            ->withToken($token)
            ->withHeaders([
                'Accept'       => 'application/json',
                'X-Request-Id' => $this->context->requestId(),
            ])
            ->timeout($timeout)
            ->acceptJson();

        $start = microtime(true);

        try {
            $response = match ($upperMethod) {
                'GET'    => $pending->get($url, $query ?? []),
                'POST'   => $pending->asJson()->post($url, $body ?? []),
                'PATCH'  => $pending->asJson()->patch($url, $body ?? []),
                'PUT'    => $pending->asJson()->put($url, $body ?? []),
                'DELETE' => $pending->asJson()->delete($url, $body ?? []),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: $method"),
            };
        } catch (Throwable $e) {
            $error = ErrorMapper::map(0, ['error' => ['code' => 'network_error', 'message' => $e->getMessage()]]);
            $this->fireError($error, $upperMethod, $path, 0, $start);
            throw $error;
        }

        $status = $response->status();
        $event = $this->buildEvent($upperMethod, $path, $status, $start);

        if (! $response->successful()) {
            $error = ErrorMapper::map($status, $this->parseBody($response));
            $this->telemetry->onError($error, $event);
            throw $error;
        }

        $this->telemetry->onRequest($event);

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

    private function buildEvent(string $method, string $path, int $status, float $startSeconds): TelemetryRequestEvent
    {
        return new TelemetryRequestEvent(
            method: $method,
            path: $path,
            status: $status,
            durationMs: (microtime(true) - $startSeconds) * 1000.0,
            requestId: $this->context->requestId(),
            attempts: 1,
        );
    }

    private function fireError(RoadException $error, string $method, string $path, int $status, float $startSeconds): void
    {
        $this->telemetry->onError($error, $this->buildEvent($method, $path, $status, $startSeconds));
    }
}
