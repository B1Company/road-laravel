<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\WebhookController;
use B1Road\Laravel\Webhooks\Events\MemberSuspended;
use B1Road\Laravel\Webhooks\Events\RoadWebhookReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

const ENDPOINT_SECRET = 'whsec_endpoint';

beforeEach(function () {
    config(['road.webhooks.secret' => ENDPOINT_SECRET, 'road.webhooks.verify' => true]);
    Route::post('/road/webhooks', WebhookController::class)->middleware('road.webhook');
});

/**
 * Build a signed webhook delivery exactly as the Road API sends it: HMAC over
 * "{timestamp}.{rawBody}", `X-Road-Signature: sha256=<hex>`, epoch-second ts.
 *
 * @param  array<string,mixed>  $payload
 * @return array{0: string, 1: array<string,string>}
 */
function delivery(array $payload, ?string $secret = ENDPOINT_SECRET): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $ts = (string) time();
    $sig = 'sha256='.hash_hmac('sha256', $ts.'.'.$raw, $secret ?? ENDPOINT_SECRET);

    return [$raw, [
        'HTTP_X_ROAD_SIGNATURE' => $sig,
        'HTTP_X_ROAD_TIMESTAMP' => $ts,
        'HTTP_X_ROAD_EVENT' => (string) ($payload['event'] ?? ''),
        'HTTP_X_ROAD_DELIVERY_ID' => (string) ($payload['id'] ?? ''),
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ]];
}

it('verifies a signed delivery and dispatches the typed + generic events', function () {
    Event::fake();
    [$raw, $headers] = delivery([
        'id' => 'evt_1',
        'event' => 'organization.member.suspended',
        'timestamp' => '2026-06-11T00:00:00Z',
        'data' => ['businessUnitId' => 'bu_1', 'memberId' => 'm_1', 'userId' => 'u_1'],
    ]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)->assertOk();

    Event::assertDispatched(MemberSuspended::class, fn (MemberSuspended $e) => $e->id === 'evt_1' && $e->data->memberId === 'm_1' && $e->data->businessUnitId === 'bu_1');
    Event::assertDispatched(RoadWebhookReceived::class, fn (RoadWebhookReceived $e) => $e->event === 'organization.member.suspended');
});

it('rejects a tampered body with 401 and dispatches nothing', function () {
    Event::fake();
    [, $headers] = delivery(['id' => 'evt_1', 'event' => 'organization.member.suspended', 'data' => []]);

    // Sign one body, send another.
    $this->call('POST', '/road/webhooks', [], [], [], $headers, '{"data":{"memberId":"tampered"}}')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'webhook_signature_invalid');

    Event::assertNotDispatched(MemberSuspended::class);
    Event::assertNotDispatched(RoadWebhookReceived::class);
});

it('fails closed with 503 when no secret is configured', function () {
    config(['road.webhooks.secret' => null]);
    [$raw, $headers] = delivery(['id' => 'evt_1', 'event' => 'organization.member.joined', 'data' => []]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'webhook_not_configured');
});

it('returns 200 for an unknown event, firing only the generic event', function () {
    Event::fake();
    [$raw, $headers] = delivery([
        'id' => 'evt_x',
        'event' => 'organization.future.event',
        'timestamp' => '2026-06-11T00:00:00Z',
        'data' => ['anything' => true],
    ]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)->assertOk();

    Event::assertDispatched(RoadWebhookReceived::class);
    Event::assertNotDispatched(MemberSuspended::class);
});

it('skips verification in non-production when verify is disabled', function () {
    config(['road.webhooks.verify' => false, 'road.webhooks.secret' => null]);
    Event::fake();

    $raw = json_encode(['id' => 'evt_1', 'event' => 'organization.member.joined', 'timestamp' => 't', 'data' => ['businessUnitId' => 'bu_1', 'memberId' => 'm_1', 'userId' => 'u_1']], JSON_THROW_ON_ERROR);

    // No signature headers at all.
    $this->call('POST', '/road/webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)
        ->assertOk();

    Event::assertDispatched(RoadWebhookReceived::class);
});
