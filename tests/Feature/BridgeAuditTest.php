<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\BridgeAuditCounterparty;
use B1Road\Laravel\DTO\BridgeAuditEntry;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * `Road::client()->bridge()->audit()` — the owner-scoped Bridge audit (IR-069).
 */
function ownerClient(): RoadClient
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: 'u_owner', email: 'owner@example.com', name: 'Owner'));
    $ctx->setToken('owner-access-token');

    return app(RoadClient::class);
}

/** @return array<string,mixed> */
function auditRow(string $event, ?string $permission = null, bool $allowed = true): array
{
    return [
        'event' => $event,
        'counterparty' => ['id' => 'plat_crm', 'name' => 'Other CRM'],
        'permission' => $permission,
        'allowed' => $allowed,
        'reason' => $allowed ? 'engine_allowed' : 'provider_denied',
        'createdAt' => '2026-10-01T12:00:00.000Z',
    ];
}

it('walks every page, sending the direction and filter on each', function () {
    Http::fake([
        'api.road.test/api/alpha/developer/platforms/plat_mine/bridge/audit*' => Http::sequence()
            ->push(['data' => [auditRow('attempt', 'read:Invoice', false)], 'pagination' => ['cursor' => 'c2', 'hasMore' => true, 'totalCount' => 2]])
            ->push(['data' => [auditRow('check')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 2]]),
    ]);

    $rows = iterator_to_array(ownerClient()->bridge()->audit('plat_mine', 'inbound', allowed: false));

    expect($rows)->toHaveCount(2);
    expect($rows[0])->toBeInstanceOf(BridgeAuditEntry::class);
    expect($rows[0]->event)->toBe('attempt');
    expect($rows[0]->permission)->toBe('read:Invoice');
    expect($rows[0]->counterparty)->toBeInstanceOf(BridgeAuditCounterparty::class);
    expect($rows[0]->counterparty->id)->toBe('plat_crm');
    expect($rows[1]->event)->toBe('check');

    $queries = [];
    Http::assertSent(function (HttpRequest $request) use (&$queries) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
        $queries[] = $q;

        return true;
    });
    // The filters ride on the cursor page too, not only the first one.
    expect($queries)->toBe([
        ['direction' => 'inbound', 'allowed' => 'false'],
        ['direction' => 'inbound', 'allowed' => 'false', 'cursor' => 'c2'],
    ]);
});

it('decodes a row whose other platform Road can no longer resolve', function () {
    Http::fake([
        'api.road.test/*' => Http::response([
            'data' => [array_merge(auditRow('grant_revoked'), ['counterparty' => null])],
            'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1],
        ]),
    ]);

    $page = ownerClient()->bridge()->audit('plat_mine', 'outbound')->firstPage();

    expect($page->data[0]->counterparty)->toBeNull();
});

it('refuses an unknown direction before calling Road', function () {
    Http::fake();

    expect(fn () => ownerClient()->bridge()->audit('plat_mine', 'sideways'))
        ->toThrow(InvalidArgumentException::class, "Use 'inbound'");
    Http::assertNothingSent();
});
