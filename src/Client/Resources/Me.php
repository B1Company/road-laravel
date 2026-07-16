<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\CurrentUser;
use B1Road\Laravel\DTO\Membership;
use B1Road\Laravel\DTO\MyBusinessUnits;
use B1Road\Laravel\DTO\MyPermissions;
use B1Road\Laravel\DTO\Role;

/**
 * `Road::client()->me()->*` — the calling user's own surface. Mirrors
 * `apps/sdks/road-nestjs/src/client/resources/me.ts` and the same
 * underlying endpoints: `/iam/identity/me`, `/me/business-units`,
 * `/me/permissions`.
 */
final class Me
{
    /**
     * Max scope ids per `/iam/authorization/me/permissions?scopes=` request.
     * Mirrors the API's `MAX_BULK_SCOPES` cap (it 400s above this).
     */
    private const MAX_BULK_SCOPES = 50;

    public function __construct(private readonly HttpTransportInterface $http) {}

    public function get(): CurrentUser
    {
        $body = $this->http->request('GET', '/iam/identity/me');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return CurrentUser::from($data);
    }

    /**
     * Revoke the caller's access: `POST /iam/identity/me/logout`.
     *
     * Terminates ALL of the caller's Auth Server sessions and stamps the
     * revocation watermark, so every already-issued access token for this
     * user stops working at the Road API immediately. There is no
     * per-session variant — the access token carries no session id.
     *
     * The SDK's own logout route calls this automatically; reach for it
     * directly when building a custom sign-out flow.
     */
    public function logout(): void
    {
        $this->http->request('POST', '/iam/identity/me/logout');
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

    /**
     * The caller's memberships — a convenience over `businessUnits()->memberships`.
     * Mirrors `me().memberships()` in @b1-road/nestjs.
     *
     * @return list<Membership>
     */
    public function memberships(): array
    {
        return array_values($this->businessUnits()->memberships->all());
    }

    /**
     * Roles defined on a platform the BU subscribes to (e.g. the platform's
     * Admin/Operator roles). Resolves `(platformId, businessUnitId)` to the
     * subscription's IAM scope via the resolver endpoint, then lists that
     * scope's roles (all pages). Mirrors the NestJS SDK's `me().platformRoles()`.
     * Unlike Nest, `platformId` is required — the Laravel client has no
     * platform-id default to fall back to.
     *
     * @return list<Role>
     */
    public function platformRoles(string $platformId, string $businessUnitId): array
    {
        $resolution = $this->http->request(
            'GET',
            '/organization/business-units/'.rawurlencode($businessUnitId)
                .'/subscriptions/'.rawurlencode($platformId),
        );
        $data = is_array($resolution['data'] ?? null) ? $resolution['data'] : $resolution;
        $scopeId = (string) ($data['scopeId'] ?? '');

        return RoleCollection::forScope($this->http, $scopeId)->all();
    }

    /**
     * The signed-in user's Eduzz products. Iterable (auto-paginating) with a
     * `firstPage()` escape hatch. Road calls Eduzz with the user's server-held
     * token; the caller never sees it.
     */
    public function eduzzProducts(): EduzzProductCollection
    {
        return new EduzzProductCollection($this->http);
    }

    public function permissions(): MyPermissions
    {
        // Road exposes effective permissions per IAM **scope** (not per BU)
        // and returns `(action, subject)` tuples — there is no `/me/permissions`
        // route. Mirror @b1-road/nestjs + @b1-road/react: resolve each
        // membership's BU to its `iamScopeId`, bulk-fetch the scope-keyed
        // endpoint (chunked to the API's cap), then flatten the tuples to
        // `"action:Subject"` strings keyed back by BU id (`*:*` → `"*"`).
        // The membership summary omits the scope id, so resolve each BU's
        // `iamScopeId` from its detail. Iterating the DataCollection directly
        // keeps this empty-safe — no memberships means no BU fetches and an
        // empty result.
        /** @var array<string,string> $scopeIdByBu */
        $scopeIdByBu = [];
        foreach ($this->businessUnits()->memberships as $membership) {
            $buId = $membership->businessUnit->id;
            $detail = $this->http->request('GET', '/organization/business-units/'.rawurlencode($buId));
            $detailData = is_array($detail['data'] ?? null) ? $detail['data'] : $detail;
            $scopeIdByBu[$buId] = (string) ($detailData['iamScopeId'] ?? '');
        }
        if ($scopeIdByBu === []) {
            return new MyPermissions(byBusinessUnit: []);
        }

        // One bulk call per chunk of <= MAX_BULK_SCOPES scope ids (the API
        // 400s above the cap). Response: `{ '<scopeId>': [{action,subject}] }`.
        $uniqueScopeIds = array_values(array_unique(array_filter(array_values($scopeIdByBu))));
        /** @var array<string, list<array<string,mixed>>> $byScope */
        $byScope = [];
        foreach (array_chunk($uniqueScopeIds, self::MAX_BULK_SCOPES) as $chunkScopeIds) {
            $resp = $this->http->request('GET', '/iam/authorization/me/permissions', null, [
                'scopes' => implode(',', $chunkScopeIds),
            ]);
            $respData = is_array($resp['data'] ?? null) ? $resp['data'] : $resp;
            foreach ($respData as $scopeId => $tuples) {
                $byScope[(string) $scopeId] = is_array($tuples) ? $tuples : [];
            }
        }

        /** @var array<string, list<string>> $byBusinessUnit */
        $byBusinessUnit = [];
        foreach ($scopeIdByBu as $buId => $scopeId) {
            $byBusinessUnit[$buId] = self::tuplesToStrings($byScope[$scopeId] ?? []);
        }

        return new MyPermissions(byBusinessUnit: $byBusinessUnit);
    }

    /**
     * Flatten the API's `(action, subject)` tuples into `"action:Subject"`
     * strings, collapsing the `*:*` wildcard to `"*"`. Each non-array element
     * is skipped, so the loosely-typed wire array is accepted as-is.
     *
     * @param  array<mixed>  $tuples
     * @return list<string>
     */
    private static function tuplesToStrings(array $tuples): array
    {
        $out = [];
        foreach ($tuples as $tuple) {
            if (! is_array($tuple)) {
                continue;
            }
            $action = (string) ($tuple['action'] ?? '');
            $subject = (string) ($tuple['subject'] ?? '');
            if ($action === '') {
                continue;
            }
            $out[] = ($action === '*' && $subject === '*') ? '*' : $action.':'.$subject;
        }

        return $out;
    }
}
