<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

/**
 * Shared helper for resources: unwrap the API's `{ data: … }` envelope,
 * tolerating a fake/legacy response that returns the resource bare.
 */
trait UnwrapsData
{
    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function unwrap(array $body): array
    {
        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }
}
