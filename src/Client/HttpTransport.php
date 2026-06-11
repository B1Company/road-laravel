<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Auth\Service\ServiceTokenStore;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadRateLimitException;
use B1Road\Laravel\Exceptions\RoadServerException;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Telemetry\TelemetryRequestEvent;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Wraps Laravel's HTTP client to call the Road API. Reads the user's Bearer
 * from RoadContext per call — the indirection lets follow-ups slot a
 * service-token store in here without touching call sites.
 *
 * Mutations carry an `Idempotency-Key` so a retried attempt is de-duplicated
 * server-side. Transient failures (5xx, network) are retried with exponential
 * backoff + jitter; a 429 is never retried — its `Retry-After` is surfaced on
 * the typed {@see RoadRateLimitException} instead.
 */
final class HttpTransport implements HttpTransportInterface
{
    private const MAX_BACKOFF_MS = 10_000;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly RoadContext $context,
        private readonly ConfigRepository $config,
        private readonly RoadTelemetry $telemetry,
        private readonly ?ServiceTokenStore $serviceTokens = null,
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
        $serviceMode = $this->context->isServiceMode();
        if ($serviceMode) {
            if ($this->serviceTokens === null) {
                throw new RoadAuthnException(
                    message: 'Service mode requested but no service credentials are configured. Set ROAD_SERVICE_CLIENT_ID and a secret (or private_key_jwt config).',
                    errorCode: 'service_credentials_missing',
                );
            }
            $token = $this->serviceTokens->getToken();
        } else {
            $token = $this->context->token();
            if ($token === null || $token === '') {
                throw new RoadAuthnException(
                    message: 'No access token available on RoadContext — call Road from a `road`-protected route.',
                    errorCode: 'no_token',
                );
            }
        }

        $upperMethod = strtoupper($method);
        if (! in_array($upperMethod, ['GET', 'POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            throw new \InvalidArgumentException("Unsupported HTTP method: $method");
        }

        $url = $this->buildUrl($path);
        $timeout = (int) $this->config->get('road.api.timeout', 10);

        $retryEnabled = (bool) $this->config->get('road.api.retry.enabled', true);
        $maxAttempts = $retryEnabled ? max(1, (int) $this->config->get('road.api.retry.max_attempts', 3)) : 1;
        $baseDelayMs = max(0, (int) $this->config->get('road.api.retry.base_delay_ms', 250));

        $headers = [
            'Accept' => 'application/json',
            'X-Request-Id' => $this->context->requestId(),
        ];
        // A mutation carries one stable Idempotency-Key for the lifetime of the
        // call — generated before the retry loop so every replay sends the same
        // key and Road applies the effect at most once.
        if ($upperMethod !== 'GET') {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }

        $start = microtime(true);
        $authRetried = false;

        for ($attempt = 1; ; $attempt++) {
            $pending = $this->http
                ->withToken($token)
                ->withHeaders($headers)
                ->timeout($timeout)
                ->acceptJson();

            try {
                $response = match ($upperMethod) {
                    'GET' => $pending->get($url, $query ?? []),
                    'POST' => $pending->asJson()->post($url, $body ?? []),
                    'PATCH' => $pending->asJson()->patch($url, $body ?? []),
                    'PUT' => $pending->asJson()->put($url, $body ?? []),
                    'DELETE' => $pending->asJson()->delete($url, $body ?? []),
                };
            } catch (Throwable $e) {
                $error = new RoadNetworkException(
                    message: $e->getMessage() !== '' ? $e->getMessage() : 'Network error talking to Road API.',
                    previous: $e,
                );
                if ($attempt < $maxAttempts) {
                    $this->backoff($attempt, $baseDelayMs);

                    continue;
                }
                $this->telemetry->onError($error, $this->buildEvent($upperMethod, $path, 0, $start, null, $attempt));

                throw $error;
            }

            $status = $response->status();

            if ($response->successful()) {
                $this->telemetry->onRequest($this->buildEvent($upperMethod, $path, $status, $start, $response, $attempt));

                return $this->parseBody($response) ?? [];
            }

            $error = ErrorMapper::map(
                $status,
                $this->parseBody($response),
                ['Retry-After' => $response->header('Retry-After')],
            );

            // In service mode a 401 means the cached token was rejected
            // (rotated/expired server-side). Drop it and retry once with a
            // freshly-acquired token. Independent of the transient-retry budget.
            if ($serviceMode && $status === 401 && ! $authRetried && $this->serviceTokens !== null) {
                $authRetried = true;
                $this->serviceTokens->invalidate();
                $token = $this->serviceTokens->getToken();

                continue;
            }

            if ($attempt < $maxAttempts && $this->shouldRetry($error)) {
                $this->backoff($attempt, $baseDelayMs);

                continue;
            }

            $this->telemetry->onError($error, $this->buildEvent($upperMethod, $path, $status, $start, $response, $attempt));

            throw $error;
        }
    }

    /** Only transient failures are retried; 4xx (incl. 429) never are. */
    private function shouldRetry(RoadException $error): bool
    {
        return $error instanceof RoadServerException || $error instanceof RoadNetworkException;
    }

    /**
     * Exponential backoff with full jitter, capped. Goes through
     * Illuminate\Support\Sleep so tests can `Sleep::fake()` it and assert the
     * schedule without real waits.
     */
    private function backoff(int $attempt, int $baseDelayMs): void
    {
        if ($baseDelayMs <= 0) {
            return;
        }

        $exponential = $baseDelayMs * (2 ** ($attempt - 1));
        $delayMs = min($exponential + random_int(0, $baseDelayMs), self::MAX_BACKOFF_MS);

        Sleep::for($delayMs)->milliseconds();
    }

    private function buildUrl(string $path): string
    {
        $base = (string) $this->config->get('road.api.base_url', '');
        if ($base === '') {
            throw new \RuntimeException('road.api.base_url is not configured.');
        }

        // The Road API mounts every route under `/api/{version}` (the API's
        // `app.setGlobalPrefix('api/${apiVersion}')`). `road.api.base_url` is
        // the bare host, so the SDK composes the version prefix here — matching
        // @b1-road/nestjs and @b1-road/react. Omitting it 404s every call.
        $version = trim((string) $this->config->get('road.api.version', 'alpha'), '/');

        return rtrim($base, '/').'/api/'.$version.'/'.ltrim($path, '/');
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

    private function buildEvent(string $method, string $path, int $status, float $startSeconds, ?Response $response = null, int $attempts = 1): TelemetryRequestEvent
    {
        // `traceparent` is the W3C trace id Road echoes; matches the
        // `traceId` field @b1-road/react and @b1-road/nestjs surface so a
        // single observability sink reads every SDK's event the same way.
        $traceId = $response?->header('traceparent');

        return new TelemetryRequestEvent(
            method: $method,
            path: $path,
            status: $status,
            durationMs: (microtime(true) - $startSeconds) * 1000.0,
            requestId: $this->context->requestId(),
            traceId: ($traceId === null || $traceId === '') ? null : $traceId,
            attempts: $attempts,
        );
    }
}
