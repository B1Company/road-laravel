<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\BusinessUnitDetail;

/**
 * `Road::client()->businessUnits()->...` — the BU entry resource.
 *
 * MVP exposes `get($buId)` and `__invoke($buId)`. CRUD (`create`,
 * `update`, `archive`) lands in the full client surface follow-up.
 */
final class BusinessUnits
{
    public function __construct(private readonly HttpTransportInterface $http) {}

    public function get(string $buId): BusinessUnitDetail
    {
        $body = $this->http->request('GET', '/organization/business-units/'.rawurlencode($buId));
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return BusinessUnitDetail::from($data);
    }

    /**
     * Shorthand: `Road::client()->businessUnits($buId)` returns a scope.
     */
    public function __invoke(string $buId): BusinessUnitScope
    {
        return new BusinessUnitScope($this->http, $buId);
    }
}
