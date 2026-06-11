<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Controllers;

use B1Road\Laravel\Webhooks\Events\MemberSuspended;
use B1Road\Laravel\Webhooks\Events\RoadWebhookReceived;
use B1Road\Laravel\Webhooks\RoadEventMap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives verified Road webhook deliveries (signature already checked by the
 * `road.webhook` middleware) and dispatches them onto Laravel's event bus.
 * Every delivery fires {@see RoadWebhookReceived}; a known event type also
 * fires its typed event (e.g. {@see MemberSuspended})
 * so integrators register ordinary listeners. Unknown event types still
 * return 200 — forward-compatible with events added after this SDK shipped.
 */
final class WebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $received = RoadWebhookReceived::fromRequest($request);
        event($received);

        $mapping = RoadEventMap::for($received->event);
        if ($mapping === null) {
            Log::warning(sprintf(
                'Road webhook: no typed handler for event "%s" — fired RoadWebhookReceived only (forward-compatible).',
                $received->event,
            ));

            return response()->json(['ok' => true]);
        }

        [$eventClass, $payloadClass] = $mapping;
        event(new $eventClass($received->id, $received->timestamp, $payloadClass::from($received->data)));

        return response()->json(['ok' => true]);
    }
}
