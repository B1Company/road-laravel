<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Telemetry\TelemetryRequestEvent;
use Illuminate\Support\Facades\Http;

/**
 * Captures the last telemetry event the transport emits, so a test can
 * assert the event the SDK hands an integrator's observability sink.
 */
function spyTelemetry(): RoadTelemetry
{
    $spy = new class implements RoadTelemetry
    {
        public ?TelemetryRequestEvent $request = null;

        public ?TelemetryRequestEvent $error = null;

        public function onRequest(TelemetryRequestEvent $event): void
        {
            $this->request = $event;
        }

        public function onError(RoadException $error, TelemetryRequestEvent $event): void
        {
            $this->error = $event;
        }
    };

    app()->instance(RoadTelemetry::class, $spy);

    return $spy;
}

function seedTelemetryContext(): void
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: 'u_1', email: 'u1@example.com', name: 'User 1'));
    $ctx->setToken('test-access-token');
    $ctx->setRequestId('req_test_1');
}

it('surfaces the traceparent trace id on a successful onRequest event', function () {
    seedTelemetryContext();
    $spy = spyTelemetry();

    Http::fake([
        'api.road.test/api/alpha/iam/identity/me' => Http::response(
            ['data' => ['id' => 'u_1', 'name' => 'User 1', 'email' => 'u1@example.com']],
            200,
            ['traceparent' => '00-traceme-span-01'],
        ),
    ]);

    app(RoadClient::class)->me()->get();

    expect($spy->request)->not->toBeNull();
    expect($spy->request->requestId)->toBe('req_test_1');
    expect($spy->request->traceId)->toBe('00-traceme-span-01');
});

it('leaves traceId null when the response carries no traceparent', function () {
    seedTelemetryContext();
    $spy = spyTelemetry();

    Http::fake([
        'api.road.test/api/alpha/iam/identity/me' => Http::response(
            ['data' => ['id' => 'u_1', 'name' => 'User 1', 'email' => 'u1@example.com']],
            200,
        ),
    ]);

    app(RoadClient::class)->me()->get();

    expect($spy->request)->not->toBeNull();
    expect($spy->request->traceId)->toBeNull();
});

it('carries the trace id on an onError event', function () {
    seedTelemetryContext();
    $spy = spyTelemetry();

    Http::fake([
        '*' => Http::response(
            ['error' => ['code' => 'server_error', 'message' => 'boom']],
            500,
            ['traceparent' => '00-traceerr-span-01'],
        ),
    ]);

    try {
        app(RoadClient::class)->me()->get();
    } catch (RoadException) {
        // expected — we only care about the emitted telemetry
    }

    expect($spy->error)->not->toBeNull();
    expect($spy->error->traceId)->toBe('00-traceerr-span-01');
});
