<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Member;

/**
 * Members of a business unit. Iterable (auto-paginating) with the per-member
 * actions hung off the same object:
 *
 *   foreach (Road::client()->businessUnits($buId)->members() as $member) { ... }
 *   Road::client()->businessUnits($buId)->members()->suspend($memberId);
 *
 * Mirrors `MemberCollection` in road-nestjs's business-units resource.
 *
 * @extends Paginates<Member>
 */
final class MemberCollection extends Paginates
{
    public function __construct(HttpTransportInterface $http, private readonly string $buId)
    {
        parent::__construct($http);
    }

    public function get(string $memberId): Member
    {
        $body = $this->http->request('GET', $this->base().'/'.rawurlencode($memberId));

        return Member::from(is_array($body['data'] ?? null) ? $body['data'] : $body);
    }

    public function suspend(string $memberId): void
    {
        $this->http->request('POST', $this->base().'/'.rawurlencode($memberId).'/suspend');
    }

    public function reinstate(string $memberId): void
    {
        $this->http->request('POST', $this->base().'/'.rawurlencode($memberId).'/reinstate');
    }

    public function remove(string $memberId): void
    {
        $this->http->request('DELETE', $this->base().'/'.rawurlencode($memberId));
    }

    /**
     * Assign a role (BU or platform) to a member, by role id. Mirrors
     * `members().assignRole(memberId, roleId)` in road-nestjs.
     */
    public function assignRole(string $memberId, string $roleId): void
    {
        $this->http->request(
            'POST',
            $this->base().'/'.rawurlencode($memberId).'/roles',
            ['roleId' => $roleId],
        );
    }

    /** Revoke a role (BU or platform) from a member, by role id. */
    public function revokeRole(string $memberId, string $roleId): void
    {
        $this->http->request(
            'DELETE',
            $this->base().'/'.rawurlencode($memberId).'/roles/'.rawurlencode($roleId),
        );
    }

    protected function listPath(): string
    {
        return $this->base();
    }

    protected function mapRow(array $row): Member
    {
        return Member::from($row);
    }

    private function base(): string
    {
        return '/organization/business-units/'.rawurlencode($this->buId).'/members';
    }
}
