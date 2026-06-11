<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\PaginatedList;
use B1Road\Laravel\DTO\Pagination;
use Illuminate\Support\LazyCollection;
use IteratorAggregate;
use Traversable;

/**
 * Base for auto-paginating resource collections. The integrator writes
 * `foreach (Road::client()->businessUnits($id)->members() as $m) { ... }` and
 * never sees a cursor — iteration walks every page. `firstPage()` is the
 * escape hatch for a single bounded page, and `lazy()` hands back an
 * Illuminate LazyCollection for idiomatic chaining. Mirrors
 * `apps/sdks/road-nestjs/src/client/pagination.ts`.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
abstract class Paginates implements IteratorAggregate
{
    public function __construct(protected readonly HttpTransportInterface $http) {}

    /** The list endpoint path (may resolve a scope id on first call). */
    abstract protected function listPath(): string;

    /**
     * Map one wire row to its DTO.
     *
     * @param  array<string,mixed>  $row
     * @return T
     */
    abstract protected function mapRow(array $row): mixed;

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        $cursor = null;
        do {
            $page = $this->fetchPage($cursor, null);
            foreach ($page->data as $item) {
                yield $item;
            }
            $cursor = $page->pagination->cursor;
        } while ($cursor !== null && $cursor !== '');
    }

    /**
     * One bounded page plus its cursor — the rare case iteration doesn't fit.
     *
     * @return PaginatedList<T>
     */
    public function firstPage(?int $limit = null): PaginatedList
    {
        return $this->fetchPage(null, $limit);
    }

    /**
     * Eagerly collect every row (optionally capped). Convenience; prefer
     * iteration for large lists.
     *
     * @return list<T>
     */
    public function all(?int $maxItems = null): array
    {
        $out = [];
        foreach ($this as $item) {
            $out[] = $item;
            if ($maxItems !== null && count($out) >= $maxItems) {
                break;
            }
        }

        return $out;
    }

    /**
     * A lazily-evaluated Illuminate collection over every row — `->filter()`,
     * `->take()`, `->map()` without buffering the whole listing.
     *
     * @return LazyCollection<int, T>
     */
    public function lazy(): LazyCollection
    {
        return LazyCollection::make(function () {
            yield from $this;
        });
    }

    /** @return PaginatedList<T> */
    protected function fetchPage(?string $cursor, ?int $limit): PaginatedList
    {
        $query = [];
        if ($cursor !== null && $cursor !== '') {
            $query['cursor'] = $cursor;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }

        return $this->normalize($this->http->request('GET', $this->listPath(), null, $query));
    }

    /**
     * Tolerate the three envelope shapes a Road list endpoint can answer with:
     * `{ data:[], pagination }`, `{ data:[], meta:{ pagination } }`, and the
     * SDK-shape `{ data: { data:[], pagination } }`. Synthesise a single-page
     * result when no pagination metadata is present.
     *
     * @param  array<string,mixed>  $body
     * @return PaginatedList<T>
     */
    private function normalize(array $body): PaginatedList
    {
        $data = $body['data'] ?? [];
        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $rows = $data['data'];
            $pagination = $data['pagination'] ?? null;
        } else {
            $rows = is_array($data) ? $data : [];
            $meta = $body['meta'] ?? null;
            $pagination = $body['pagination'] ?? (is_array($meta) ? ($meta['pagination'] ?? null) : null);
        }

        $mapped = [];
        foreach (array_values($rows) as $row) {
            if (is_array($row)) {
                $mapped[] = $this->mapRow($row);
            }
        }

        return new PaginatedList(
            data: $mapped,
            pagination: Pagination::fromWire(is_array($pagination) ? $pagination : null, count($mapped)),
        );
    }
}
