<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\DTO\EduzzProduct;
use B1Road\Laravel\Exceptions\RoadException;

/**
 * The authenticated user's Eduzz products (`GET /me/eduzz/products`). Iterable
 * (auto-paginating) like every other Road listing:
 *
 *   foreach (Road::client()->me()->eduzzProducts() as $product) { ... }
 *   $page = Road::client()->me()->eduzzProducts()->firstPage();
 *
 * Road calls Eduzz with the user's server-held token; a `403` carrying
 * `EDUZZ_REAUTH_REQUIRED` means the user must reconnect Eduzz,
 * `EDUZZ_SCOPE_MISSING` is an integration-config issue, and a `503` with
 * `EDUZZ_UPSTREAM_UNAVAILABLE` is a transient upstream outage — the codes ride
 * in the RFC 7807 problem `detail`, so they survive on the thrown
 * {@see RoadException}. Eduzz paginates by offset, so
 * v1 returns a single page.
 *
 * @extends Paginates<EduzzProduct>
 */
final class EduzzProductCollection extends Paginates
{
    protected function listPath(): string
    {
        return '/me/eduzz/products';
    }

    protected function mapRow(array $row): EduzzProduct
    {
        return EduzzProduct::from($row);
    }
}
