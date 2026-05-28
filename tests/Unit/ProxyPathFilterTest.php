<?php

declare(strict_types=1);

use B1Road\Laravel\Http\ProxyPathFilter;

$allow = ['organization/*', 'iam/identity/*', 'iam/authorization/*'];

it('allows paths matching the allow-list', function () use ($allow) {
    $filter = new ProxyPathFilter($allow);

    expect($filter->isAllowed('organization/business-units/bu_1'))->toBeTrue();
    expect($filter->isAllowed('iam/identity/me'))->toBeTrue();
    expect($filter->isAllowed('iam/authorization/authorize'))->toBeTrue();
});

it('rejects paths outside the allow-list', function () use ($allow) {
    $filter = new ProxyPathFilter($allow);

    expect($filter->isAllowed('private/secrets'))->toBeFalse();
    expect($filter->isAllowed('billing/invoices'))->toBeFalse();
});

it('rejects path-traversal segments even within allow-listed prefixes', function (string $hostile) use ($allow) {
    $filter = new ProxyPathFilter($allow);

    expect($filter->isAllowed($hostile))->toBeFalse();
})->with([
    'dotdot segment'        => ['organization/../private'],
    'dot segment'           => ['organization/./secrets'],
    'leading dotdot'        => ['../organization'],
    'encoded dotdot'        => ['organization/%2e%2e/private'],
    'encoded dot'           => ['organization/%2e/secrets'],
    'double slash'          => ['organization//double'],
    'backslash'             => ['organization\\backdoor'],
    'null byte injection'   => ["organization/bu\x00admin"],
    'crlf header injection' => ["organization/bu\r\nX-Injected: 1"],
]);

it('allows everything when the allow-list is empty', function () {
    $filter = new ProxyPathFilter([]);

    expect($filter->isAllowed('anything/at-all'))->toBeTrue();
    // …but still rejects unsafe paths.
    expect($filter->isAllowed('anything/../else'))->toBeFalse();
});
