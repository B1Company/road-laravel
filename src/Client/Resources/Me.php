<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\CurrentUser;
use B1Road\Laravel\DTO\MyBusinessUnits;
use B1Road\Laravel\DTO\MyPermissions;

/**
 * `Road::client()->me()->*` — the calling user's own surface. Mirrors
 * `apps/sdks/road-nestjs/src/client/resources/me.ts` and the same
 * underlying endpoints: `/iam/identity/me`, `/me/business-units`,
 * `/me/permissions`.
 */
final class Me
{
    public function __construct(private readonly HttpTransportInterface $http) {}

    public function get(): CurrentUser
    {
        $body = $this->http->request('GET', '/iam/identity/me');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return CurrentUser::from($data);
    }

    public function businessUnits(): MyBusinessUnits
    {
        $body = $this->http->request('GET', '/me/business-units');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return MyBusinessUnits::from([
            'memberships' => $data['memberships'] ?? [],
            'pendingInvitations' => $data['pendingInvitations'] ?? [],
        ]);
    }

    public function permissions(): MyPermissions
    {
        $body = $this->http->request('GET', '/me/permissions');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        // The wire shape is `{ businessUnits: { '<buId>': string[] } }` or
        // similar; accept the array as-is and stash under byBusinessUnit so
        // the typed wrapper remains stable across response-shape evolutions.
        $byBu = $data['byBusinessUnit'] ?? $data['businessUnits'] ?? $data;

        return new MyPermissions(byBusinessUnit: is_array($byBu) ? $byBu : []);
    }
}
