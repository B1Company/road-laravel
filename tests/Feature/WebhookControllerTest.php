<?php

declare(strict_types=1);

use B1Road\Laravel\Http\Controllers\WebhookController;
use B1Road\Laravel\Webhooks\Events\BridgeGrantCreated;
use B1Road\Laravel\Webhooks\Events\ExtensionInstallUninstalled;
use B1Road\Laravel\Webhooks\Events\MemberSuspended;
use B1Road\Laravel\Webhooks\Events\RoadWebhookReceived;
use B1Road\Laravel\Webhooks\RoadEventMap;
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

it('dispatches the right typed event for every event in the catalog', function (string $event) {
    Event::fake();

    $data = match (true) {
        str_starts_with($event, 'bridge.grant.') => [
            'providerPublicId' => 'plat_provider',
            'subjectPlatformPublicId' => 'plat_subject',
            'roleTemplateName' => 'Viewer',
            'grantId' => 'asg_1',
        ],
        str_starts_with($event, 'extension.install.') => [
            'extensionPublicId' => 'ext_1',
            'installPublicId' => 'exti_1',
            'businessUnitId' => 'bu_1',
            'platformPublicId' => 'plat_host',
            'grantedScopes' => ['Viewer'],
        ],
        str_contains($event, 'invitation') => [
            'businessUnitId' => 'bu_1',
            'invitationId' => 'inv_1',
            'email' => 'e@b1.app',
        ],
        default => ['businessUnitId' => 'bu_1', 'memberId' => 'm_1', 'userId' => 'u_1'],
    };
    if ($event === 'organization.member.role-changed') {
        $data['roleId'] = 'r_1';
        $data['action'] = 'assigned';
    }

    [$raw, $headers] = delivery(['id' => 'evt', 'event' => $event, 'timestamp' => '2026-06-11T00:00:00Z', 'data' => $data]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)->assertOk();

    [$eventClass] = RoadEventMap::for($event);
    Event::assertDispatched($eventClass);
    Event::assertDispatched(RoadWebhookReceived::class);
})->with(RoadEventMap::eventTypes());

it('skips verification in non-production when verify is disabled', function () {
    config(['road.webhooks.verify' => false, 'road.webhooks.secret' => null]);
    Event::fake();

    $raw = json_encode(['id' => 'evt_1', 'event' => 'organization.member.joined', 'timestamp' => 't', 'data' => ['businessUnitId' => 'bu_1', 'memberId' => 'm_1', 'userId' => 'u_1']], JSON_THROW_ON_ERROR);

    // No signature headers at all.
    $this->call('POST', '/road/webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $raw)
        ->assertOk();

    Event::assertDispatched(RoadWebhookReceived::class);
});

/**
 * Dispatch alone only proves the map has an entry. These two pin the *values*,
 * so renaming a field on either payload goes red instead of quietly hydrating
 * a half-empty DTO (B1-458).
 */
it('decodes a bridge grant payload, and does not invent a business unit', function () {
    Event::fake();

    [$raw, $headers] = delivery([
        'id' => 'evt_bridge',
        'event' => 'bridge.grant.created',
        'timestamp' => '2026-09-10T00:00:00Z',
        'data' => [
            'providerPublicId' => 'plat_provider',
            'subjectPlatformPublicId' => 'plat_subject',
            'roleTemplateName' => 'Viewer',
            'grantId' => 'asg_1',
        ],
    ]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)->assertOk();

    Event::assertDispatched(BridgeGrantCreated::class, function (BridgeGrantCreated $e) {
        expect($e->data->providerPublicId)->toBe('plat_provider');
        expect($e->data->subjectPlatformPublicId)->toBe('plat_subject');
        expect($e->data->roleTemplateName)->toBe('Viewer');
        expect($e->data->grantId)->toBe('asg_1');
        // A grant is cross-platform. A business unit here would route it
        // through the BU fan-out to every co-subscribed platform (API-F4).
        expect(property_exists($e->data, 'businessUnitId'))->toBeFalse();

        return true;
    });
});

it('decodes an extension install payload, with granted scopes empty on uninstall', function () {
    Event::fake();

    [$raw, $headers] = delivery([
        'id' => 'evt_ext',
        'event' => 'extension.install.uninstalled',
        'timestamp' => '2026-09-10T00:00:00Z',
        'data' => [
            'extensionPublicId' => 'ext_1',
            'installPublicId' => 'exti_1',
            'businessUnitId' => 'bu_1',
            'platformPublicId' => 'plat_host',
            'grantedScopes' => [],
        ],
    ]);

    $this->call('POST', '/road/webhooks', [], [], [], $headers, $raw)->assertOk();

    Event::assertDispatched(ExtensionInstallUninstalled::class, function (ExtensionInstallUninstalled $e) {
        expect($e->data->extensionPublicId)->toBe('ext_1');
        expect($e->data->installPublicId)->toBe('exti_1');
        expect($e->data->businessUnitId)->toBe('bu_1');
        expect($e->data->platformPublicId)->toBe('plat_host');
        expect($e->data->grantedScopes)->toBe([]);

        return true;
    });
});
