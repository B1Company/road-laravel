<?php

declare(strict_types=1);

use B1Road\Laravel\Client\Resources\RoleWire;
use B1Road\Laravel\DTO\BusinessUnitDetail;
use B1Road\Laravel\DTO\Pagination;
use B1Road\Laravel\DTO\Role;

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
        default => throw new RuntimeException("Unhandled contract fixture kind in {$file}"),
    };
})->with(contractFixtures());
