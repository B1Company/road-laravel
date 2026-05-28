<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\BusinessUnitDetail;

/**
 * Lightweight "navigator" returned by `Road::client()->businessUnits($buId)`.
 * No network call until the integrator asks for something concrete.
 *
 * MVP exposes only `fetch()`. The `members()` / `roles()` /
 * `invitations()` accessors land with the full client surface follow-up.
 */
final class BusinessUnitScope
{
    public function __construct(
        private readonly HttpTransportInterface $http,
        public readonly string $buId,
    ) {}

    public function fetch(): BusinessUnitDetail
    {
        $body = $this->http->request('GET', '/organization/business-units/'.rawurlencode($this->buId));
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return BusinessUnitDetail::from($data);
    }
}
