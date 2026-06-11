<?php

declare(strict_types=1);

use B1Road\Laravel\DTO\PaginatedList;
use B1Road\Laravel\DTO\Pagination;
use B1Road\Laravel\DTO\ParentScopeRef;
use B1Road\Laravel\DTO\PendingInvitation;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadWebhookSignatureException;

it('hydrates ParentScopeRef from the wire', function () {
    $ref = ParentScopeRef::from(['id' => 's_parent', 'type' => 'business_unit', 'externalId' => 'ext-9']);

    expect($ref->id)->toBe('s_parent');
    expect($ref->type)->toBe('business_unit');
    expect($ref->externalId)->toBe('ext-9');
});

it('synthesises a single-page Pagination when the wire omits it', function () {
    $pagination = Pagination::fromWire(null, 7);

    expect($pagination->cursor)->toBeNull();
    expect($pagination->hasMore)->toBeFalse();
    expect($pagination->totalCount)->toBe(7);
});

it('maps the webhook signature exception to 401 and self-documents', function () {
    $e = new RoadWebhookSignatureException;

    expect($e->httpStatus())->toBe(401);
    expect($e->errorCode())->toBe('webhook_signature_invalid');
    expect($e->toErrorBody()['docs'])->toContain('webhook-signature-invalid');
});

it('gives the network exception a 502 status and stable code', function () {
    $e = new RoadNetworkException;

    expect($e->httpStatus())->toBe(502);
    expect($e->errorCode())->toBe('network_error');
});

it('hydrates a PendingInvitation with its nested business unit', function () {
    $invitation = PendingInvitation::from([
        'id' => 'inv_1',
        'businessUnit' => ['id' => 'bu_1', 'name' => 'B1', 'slug' => 'b1'],
        'roleName' => 'Member',
        'expiresAt' => '2026-02-01T00:00:00Z',
    ]);

    expect($invitation->roleName)->toBe('Member');
    expect($invitation->businessUnit->slug)->toBe('b1');
});

it('serialises a PaginatedList including its DTO rows', function () {
    $page = new PaginatedList(
        [new ParentScopeRef('s1', 'business_unit', 'ext')],
        Pagination::fromWire(['cursor' => 'c2', 'hasMore' => true, 'totalCount' => 5], 1),
    );

    $array = $page->toArray();

    expect($array['data'][0]['id'])->toBe('s1');
    expect($array['pagination']['cursor'])->toBe('c2');
    expect($array['pagination']['totalCount'])->toBe(5);
});
