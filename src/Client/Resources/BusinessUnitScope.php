<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\DTO\BusinessUnitDetail;
use BadMethodCallException;

/**
 * Lightweight "navigator" returned by `Road::client()->businessUnits($buId)`.
 * No network call until the integrator asks for something concrete.
 *
 * MVP exposes only `fetch()`; the `members()`/`roles()`/`invitations()`
 * accessors throw — they land in the full client surface follow-up.
 */
final class BusinessUnitScope
{
    public function __construct(
        private readonly HttpTransport $http,
        public readonly string $buId,
    ) {
    }

    public function fetch(): BusinessUnitDetail
    {
        $body = $this->http->request('GET', '/organization/business-units/'.rawurlencode($this->buId));
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return BusinessUnitDetail::from($data);
    }

    public function members(): never
    {
        throw new BadMethodCallException(
            'BusinessUnitScope::members() is reserved for a follow-up release (full client surface).'
        );
    }

    public function roles(): never
    {
        throw new BadMethodCallException(
            'BusinessUnitScope::roles() is reserved for a follow-up release (full client surface).'
        );
    }

    public function invitations(): never
    {
        throw new BadMethodCallException(
            'BusinessUnitScope::invitations() is reserved for a follow-up release (full client surface).'
        );
    }
}
