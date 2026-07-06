<?php

declare(strict_types=1);

use B1Road\Laravel\Client\Resources\RoleWire;
use B1Road\Laravel\DTO\BusinessUnitDetail;
use B1Road\Laravel\DTO\MyBusinessUnits;
use B1Road\Laravel\DTO\Pagination;
use B1Road\Laravel\DTO\PlatformSubscriptionResolution;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\Webhooks\RoadEventMap;

/**
 * Replays the recorded cross-SDK contract fixtures through the Laravel DTOs.
 * These same `apps/sdks/contract-fixtures/*.json` snapshots are asserted by the
 * TypeScript SDKs, so decoding them here proves the Laravel types describe the
 * same wire — drift protection with zero network. Mirrors plan 16's pillar 3.
 */
function contractFixtures(): array
{
    $dir = dirname(__DIR__, 2).'/../contract-fixtures';
    $files = glob($dir.'/*.json') ?: [];

    return collect($files)
        ->mapWithKeys(function (string $path): array {
            /** @var array<string,mixed> $decoded */
            $decoded = json_decode((string) file_get_contents($path), associative: true);

            // Each dataset entry is a positional [file, fixture] pair, keyed by
            // filename for a readable test name.
            return [basename($path) => [basename($path), $decoded]];
        })
        ->all();
}

it('decodes every recorded contract fixture through the matching DTO', function (string $file, array $fixture) {
    // Non-wire fixtures (e.g. the webhook signature vector) live alongside the
    // recorded responses but aren't DTO-decodable — they're guarded by their own
    // tests. Skip anything without a `{ body: { data } }` wire envelope.
    if (! isset($fixture['body'])) {
        expect($fixture)->toBeArray();

        return;
    }

    /** @var array<string,mixed> $data */
    $data = $fixture['body']['data'] ?? [];

    match ($fixture['kind'] ?? '') {
        'businessUnitDetail' => (function () use ($data) {
            $bu = BusinessUnitDetail::from($data);
            expect($bu)->toBeInstanceOf(BusinessUnitDetail::class);
            expect($bu->iamScopeId)->toBe($data['iamScopeId']);
            expect($bu->memberCount)->toBe($data['memberCount']);
        })(),
        'roleList' => (function () use ($data, $fixture) {
            expect($data)->toBeArray();
            $roles = array_map(fn (array $row) => RoleWire::toDomain($row), $data);
            expect($roles[0])->toBeInstanceOf(Role::class);
            expect($roles[0]->name)->toBe($data[0]['name']);
            // The pagination envelope decodes too.
            $pagination = Pagination::fromWire($fixture['body']['pagination'] ?? null, count($roles));
            expect($pagination->hasMore)->toBeTrue();
        })(),
        'myBusinessUnits' => (function () use ($data) {
            // The membership list carries the new `platformSubscriptions` array;
            // spatie ignores properties the DTO doesn't yet surface, so this
            // decodes today — exposing those fields is Phase 1 (C1).
            $mine = MyBusinessUnits::from($data);
            expect($mine)->toBeInstanceOf(MyBusinessUnits::class);
            expect($mine->memberships)->toHaveCount(count($data['memberships']));
        })(),
        'platformSubscriptionResolution' => (function () use ($data) {
            $resolution = PlatformSubscriptionResolution::from($data);
            expect($resolution->subscriptionId)->toBe($data['subscriptionId']);
            expect($resolution->scopeId)->toBe($data['scopeId']);
        })(),
        'webhookDelivery' => (function () use ($fixture) {
            // A delivery envelope `{ id, event, timestamp, data }` — decode its
            // payload through the same DTO the receiver dispatches, so the
            // recorded wire and the typed event stay in lockstep.
            $event = $fixture['body']['event'];
            $mapping = RoadEventMap::for($event);
            expect($mapping)->not->toBeNull("no RoadEventMap entry for {$event}");
            [, $payloadClass] = $mapping;
            $payload = $payloadClass::from($fixture['body']['data']);
            expect($payload)->toBeInstanceOf($payloadClass);
        })(),
        default => throw new RuntimeException("Unhandled contract fixture kind in {$file}"),
    };
})->with(contractFixtures());
