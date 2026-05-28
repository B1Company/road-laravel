<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\DTO\BusinessUnitDetail;
use BadMethodCallException;

/**
 * `Road::client()->businessUnits()->...` — the BU entry resource.
 *
 * MVP exposes `get($buId)` and `__invoke($buId)` (the navigator
 * shorthand `Road::client()->businessUnits($buId)`). CRUD (`create`,
 * `update`, `archive`) lands in the full client surface follow-up.
 */
final class BusinessUnits
{
    public function __construct(private readonly HttpTransport $http)
    {
    }

    public function get(string $buId): BusinessUnitDetail
    {
        $body = $this->http->request('GET', '/organization/business-units/'.rawurlencode($buId));
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return BusinessUnitDetail::from($data);
    }

    /**
     * Shorthand: `Road::client()->businessUnits($buId)` returns a scope.
     * PHP magic method — when the instance is called as a function.
     */
    public function __invoke(string $buId): BusinessUnitScope
    {
        return new BusinessUnitScope($this->http, $buId);
    }

    public function create(): never
    {
        throw new BadMethodCallException(
            'BusinessUnits::create() is reserved for a follow-up release.'
        );
    }

    public function update(): never
    {
        throw new BadMethodCallException(
            'BusinessUnits::update() is reserved for a follow-up release.'
        );
    }

    public function archive(): never
    {
        throw new BadMethodCallException(
            'BusinessUnits::archive() is reserved for a follow-up release.'
        );
    }
}
