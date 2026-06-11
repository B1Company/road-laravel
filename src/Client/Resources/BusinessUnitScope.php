<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\BusinessUnitDetail;

/**
 * Lightweight "navigator" returned by `Road::client()->businessUnits($buId)`.
 * No network call until the integrator asks for something concrete — and the
 * returned collections act on the BU they came from (SDK_DX_BAR principle #3):
 *
 *   $scope = Road::client()->businessUnits($buId);
 *   foreach ($scope->members() as $member) { ... }
 *   $scope->roles()->create([...]);
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

    public function members(): MemberCollection
    {
        return new MemberCollection($this->http, $this->buId);
    }

    public function roles(): RoleCollection
    {
        return RoleCollection::forBusinessUnit($this->http, $this->buId);
    }

    public function invitations(): InvitationCollection
    {
        return new InvitationCollection($this->http, $this->buId);
    }
}
