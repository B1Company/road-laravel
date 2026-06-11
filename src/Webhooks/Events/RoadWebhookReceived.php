<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Events;

use Illuminate\Http\Request;

/**
 * The catch-all webhook event — fired for every verified delivery, before any
 * typed event. Listen to this for a single handler over all events, or to a
 * typed event (e.g. {@see MemberSuspended}) for one. Carries the raw envelope
 * `{ id, event, timestamp, data }` plus the Road delivery headers.
 */
final class RoadWebhookReceived
{
    /**
     * @param  array<string,mixed>  $data
     * @param  array<string,string>  $headers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $event,
        public readonly string $timestamp,
        public readonly array $data,
        public readonly array $headers = [],
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var array<string,mixed> $body */
        $body = $request->json()->all();
        /** @var array<string,mixed> $data */
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        return new self(
            id: (string) ($body['id'] ?? $request->header('X-Road-Delivery-Id', '')),
            event: (string) ($body['event'] ?? $request->header('X-Road-Event', '')),
            timestamp: (string) ($body['timestamp'] ?? ''),
            data: $data,
            headers: [
                'X-Road-Event' => (string) $request->header('X-Road-Event', ''),
                'X-Road-Delivery-Id' => (string) $request->header('X-Road-Delivery-Id', ''),
                'X-Road-Timestamp' => (string) $request->header('X-Road-Timestamp', ''),
            ],
        );
    }
}
