<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * One bounded page of a listing — the escape hatch returned by
 * `->firstPage()`. The default access pattern is iteration
 * (`foreach (...->members() as $m)`), which transparently follows cursors;
 * reach for this only when you genuinely want a single page plus the cursor.
 *
 * `$data` holds already-hydrated DTOs (e.g. {@see Member}), so this is a thin
 * container rather than a wire-hydrated Data object.
 *
 * @template T
 */
final class PaginatedList
{
    /** @param  list<T>  $data */
    public function __construct(
        public readonly array $data,
        public readonly Pagination $pagination,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'data' => array_map(
                static fn ($item) => $item instanceof Data ? $item->toArray() : $item,
                $this->data,
            ),
            'pagination' => $this->pagination->toArray(),
        ];
    }
}
