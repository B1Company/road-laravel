<?php

declare(strict_types=1);

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadRateLimitException;
use B1Road\Laravel\Exceptions\RoadServerException;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Telemetry\TelemetryRequestEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function transport(): HttpTransportInterface
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setToken('test-access-token');
    $ctx->setRequestId('req_retry');

    return app(HttpTransportInterface::class);
}

function serverErrorBody(): array
{
    return [
        'type' => 'https://api.road.b1.app/errors/internal-error',
        'title' => 'Internal Server Error',
        'status' => 500,
        'detail' => 'boom',
        'meta' => ['requestId' => 'req_retry'],
    ];
}

it('retries a 5xx up to max_attempts, then throws RoadServerException', function () {
    Http::fake(['*' => Http::response(serverErrorBody(), 500)]);

    expect(fn () => transport()->request('GET', '/organization/business-units/bu_1'))
        ->toThrow(RoadServerException::class);

    Http::assertSentCount(3); // max_attempts default
});

it('retries a network failure, then succeeds without surfacing the blip', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        if ($calls < 3) {
            throw new ConnectionException('connection reset');
        }

        return Http::response(['data' => ['ok' => true]], 200);
    });

    $body = transport()->request('GET', '/organization/business-units/bu_1');

    expect($body)->toBe(['data' => ['ok' => true]]);
    expect($calls)->toBe(3);
});

it('throws RoadNetworkException once retries are exhausted', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    expect(fn () => transport()->request('GET', '/x'))
        ->toThrow(RoadNetworkException::class);
});

it('returns the body when a retry succeeds on the second attempt', function () {
    Http::fakeSequence()
        ->push(serverErrorBody(), 500)
        ->push(['data' => ['id' => 'ok']], 200);

    $body = transport()->request('GET', '/organization/business-units/bu_1');

    expect($body)->toBe(['data' => ['id' => 'ok']]);
    Http::assertSentCount(2);
});

it('never retries a 429 and surfaces Retry-After on the typed exception', function () {
    Http::fake(['*' => Http::response(
        ['type' => 'https://api.road.b1.app/errors/rate-limited', 'title' => 'Rate Limit Exceeded', 'status' => 429, 'detail' => 'slow down'],
        429,
        ['Retry-After' => '7'],
    )]);

    try {
        transport()->request('GET', '/x');
        $this->fail('expected RoadRateLimitException');
    } catch (RoadRateLimitException $e) {
        expect($e->retryAfter)->toBe(7);
    }

    Http::assertSentCount(1);
});

it('attaches a stable Idempotency-Key to a mutation across retries', function () {
    Http::fakeSequence()
        ->push(serverErrorBody(), 500)
        ->push(['data' => ['id' => 'bu_new']], 201);

    transport()->request('POST', '/organization/business-units', ['name' => 'B1']);

    $keys = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0] ?? null);

    expect($keys)->toHaveCount(2);
    expect($keys[0])->not->toBeNull();
    expect($keys[0])->toBe($keys[1]); // replayed, not regenerated
});

it('does not attach an Idempotency-Key to a GET', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    transport()->request('GET', '/x');

    Http::assertSent(fn (HttpRequest $req) => ! $req->hasHeader('Idempotency-Key'));
});

it('reports the real attempt count on the telemetry error event', function () {
    $spy = new class implements RoadTelemetry
    {
        public ?TelemetryRequestEvent $error = null;

        public function onRequest(TelemetryRequestEvent $event): void {}

        public function onError(RoadException $error, TelemetryRequestEvent $event): void
        {
            $this->error = $event;
        }
    };
    app()->instance(RoadTelemetry::class, $spy);

    Http::fake(['*' => Http::response(serverErrorBody(), 500)]);

    try {
        transport()->request('GET', '/x');
    } catch (RoadServerException) {
        // expected
    }

    expect($spy->error)->not->toBeNull();
    expect($spy->error->attempts)->toBe(3);
});

it('backs off between retries through the fakeable Sleep helper', function () {
    config(['road.api.retry.base_delay_ms' => 50]);
    Sleep::fake();

    Http::fake(['*' => Http::response(serverErrorBody(), 500)]);

    try {
        transport()->request('GET', '/x');
    } catch (RoadServerException) {
        // expected
    }

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2); // two waits between three attempts
});
