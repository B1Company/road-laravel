<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Cursor-pagination metadata. Wire shape of `@b1-road/types` `Pagination`.
 * `cursor` is null on the last page; `hasMore` is the redundant
 * "rows after this page" signal.
 */
final class Pagination extends Data
{
    public function __construct(
        public ?string $cursor,
        public bool $hasMore,
        public int $totalCount,
    ) {}

    /**
     * Build from a wire pagination object, synthesising a single-page result
     * when the endpoint returned no pagination metadata.
     *
     * @param  array<string,mixed>|null  $wire
     */
    public static function fromWire(?array $wire, int $fallbackCount): self
    {
        if ($wire === null) {
            return new self(cursor: null, hasMore: false, totalCount: $fallbackCount);
        }

        $cursor = $wire['cursor'] ?? null;

        return new self(
            cursor: is_string($cursor) && $cursor !== '' ? $cursor : null,
            hasMore: (bool) ($wire['hasMore'] ?? false),
            totalCount: (int) ($wire['totalCount'] ?? $fallbackCount),
        );
    }
}
