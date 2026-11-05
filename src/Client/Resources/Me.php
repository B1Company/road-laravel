<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Authorization\Permission;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\CurrentUser;
use B1Road\Laravel\DTO\Membership;
use B1Road\Laravel\DTO\MyBusinessUnits;
use B1Road\Laravel\DTO\MyPermissions;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\Exceptions\RoadApiException;

/**
 * `Road::client()->me()->*` — the calling user's own surface. Mirrors
 * `apps/sdks/road-node-core/src/client/resources/me.ts` and the same
 * underlying endpoints: `/me/profile`, `/me/business-units`,
 * `/me/permissions`.
 */
final class Me
{
    /**
     * Max scope ids per `/authz/me/permissions?scopes=` request. Mirrors the
     * API's cap (it 400s above this).
     */
    private const MAX_BULK_SCOPES = 50;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /**
     * The caller's profile: `GET /me/profile`. Its `id` is the Road user id,
     * not the Auth Server id `Road::userId()` returns.
     */
    public function get(): CurrentUser
    {
        $body = $this->http->request('GET', '/me/profile');
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return CurrentUser::from($data);
    }

    /**
     * Whether an Eduzz support agent opened the caller's sign-in session:
     * `GET /me/session`. Pass the `sid` claim of the ID token from the same
     * sign-in; a session that is not the caller's is a 404.
     */
    public function supportSession(string $sessionId): bool
    {
        $body = $this->http->request('GET', '/me/session', query: ['sessionId' => $sessionId]);
        $answer = is_array($body['data'] ?? null) ? ($body['data']['supportSession'] ?? null) : null;
        // An answer that is not a boolean is no answer: throwing keeps it
        // from being kept as a `false`, and the next call asks again.
        if (! is_bool($answer)) {
            throw new RoadApiException('GET /me/session answered without a boolean data.supportSession.', 'malformed_response', 502);
        }

        return $answer;
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

    /**
     * The caller's codes at each business unit and subscription they belong to,
     * with `manage` expanded. `*` stands for a role that holds them all.
     *
     * Good for hiding a button, not for enforcing anything: `*` also reads
     * "yes" for a code the catalog never declared. Enforce with `Road::can()`.
     */
    public function permissions(): MyPermissions
    {
        // `/me/business-units` carries each subscription's scope id but not the
        // BU's own, so read that from the BU detail. Iterating the
        // DataCollection directly keeps this empty-safe: no memberships means
        // no BU fetches and an empty result.
        /** @var array<string,string> $scopeIdByBu */
        $scopeIdByBu = [];
        $subscriptionScopeIds = [];
        foreach ($this->businessUnits()->memberships as $membership) {
            $buId = $membership->businessUnit->id;
            $detail = $this->http->request('GET', '/organization/business-units/'.rawurlencode($buId));
            $detailData = is_array($detail['data'] ?? null) ? $detail['data'] : $detail;
            $scopeIdByBu[$buId] = (string) ($detailData['iamScopeId'] ?? '');
            foreach ($membership->platformSubscriptions as $subscription) {
                $subscriptionScopeIds[] = $subscription->scopeId;
            }
        }
        if ($scopeIdByBu === []) {
            return new MyPermissions(byBusinessUnit: [], byScope: []);
        }

        // One bulk call per chunk of <= MAX_BULK_SCOPES scope ids (the API
        // 400s above the cap). It answers a list of `{ scopeId, all, codes }`.
        $scopeIds = array_values(array_unique(array_filter([...array_values($scopeIdByBu), ...$subscriptionScopeIds])));
        /** @var array<string, list<string>> $byScope */
        $byScope = [];
        foreach (array_chunk($scopeIds, self::MAX_BULK_SCOPES) as $chunk) {
            $resp = $this->http->request('GET', '/authz/me/permissions', null, ['scopes' => implode(',', $chunk)]);
            foreach ((array) ($resp['data'] ?? []) as $effective) {
                if (is_array($effective)) {
                    $byScope[(string) ($effective['scopeId'] ?? '')] = self::codesOf($effective);
                }
            }
        }

        /** @var array<string, list<string>> $byBusinessUnit */
        $byBusinessUnit = [];
        foreach ($scopeIdByBu as $buId => $scopeId) {
            $byBusinessUnit[$buId] = $byScope[$scopeId] ?? [];
        }

        return new MyPermissions(byBusinessUnit: $byBusinessUnit, byScope: $byScope);
    }

    /**
     * One scope's codes, `*` for a holder of them all.
     *
     * @param  array<mixed>  $effective  `{ scopeId, all, codes }`
     * @return list<string>
     */
    private static function codesOf(array $effective): array
    {
        if (($effective['all'] ?? false) === true) {
            return [Permission::WILDCARD];
        }

        return array_values(array_filter((array) ($effective['codes'] ?? []), 'is_string'));
    }
}
