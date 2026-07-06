<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\EduzzProduct;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadException;
use Illuminate\Support\Facades\Http;

function seedEduzzContext(): void
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: 'u_1', email: 'u1@example.com', name: 'User 1'));
    $ctx->setToken('test-access-token');
    $ctx->setRequestId('req_eduzz_1');
}

/** @return array<string,mixed> */
function eduzzProductWire(string $id = 'prod_1'): array
{
    return [
        'id' => $id,
        'name' => 'Curso de Laravel',
        'description' => 'Do zero ao deploy',
        'producerId' => 'acc_9',
        'type' => 'digital',
        'status' => 'active',
        'author' => 'Eduardo',
        'moderation' => 'approved',
        'imageUrl' => 'https://cdn.eduzz.test/prod_1.png',
        'createdAt' => '2026-01-01T00:00:00Z',
        'updatedAt' => '2026-02-01T00:00:00Z',
        'payment' => [
            'type' => 'oneTime',
            'price' => ['currency' => 'BRL', 'value' => 19700],
            'methods' => ['bankslip' => true, 'pix' => true, 'card' => true, 'multipleCards' => false],
        ],
    ];
}

it('lists Eduzz products and decodes the full shape incl. payment (C5)', function () {
    seedEduzzContext();

    Http::fake([
        'api.road.test/api/alpha/me/eduzz/products*' => Http::response([
            'data' => [eduzzProductWire('prod_1'), eduzzProductWire('prod_2')],
            'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 2],
        ], 200),
    ]);

    $products = iterator_to_array(app(RoadClient::class)->me()->eduzzProducts());

    expect($products)->toHaveCount(2);
    expect($products[0])->toBeInstanceOf(EduzzProduct::class);
    expect($products[0]->name)->toBe('Curso de Laravel');
    // Assert *through* the nested payment DTO — not just the top level.
    expect($products[0]->payment->type)->toBe('oneTime');
    expect($products[0]->payment->price['currency'])->toBe('BRL');
    expect($products[0]->payment->methods['pix'])->toBeTrue();
});

it('exposes a firstPage() escape hatch for Eduzz products', function () {
    seedEduzzContext();

    Http::fake([
        'api.road.test/api/alpha/me/eduzz/products*' => Http::response([
            'data' => [eduzzProductWire('prod_1')],
            'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1],
        ], 200),
    ]);

    $page = app(RoadClient::class)->me()->eduzzProducts()->firstPage(limit: 10);

    expect($page->data)->toHaveCount(1);
    expect($page->data[0])->toBeInstanceOf(EduzzProduct::class);
});

it('surfaces EDUZZ_REAUTH_REQUIRED as a 403 RoadAuthzException with the code intact (C5)', function () {
    seedEduzzContext();

    Http::fake([
        'api.road.test/api/alpha/me/eduzz/products*' => Http::response([
            'type' => 'https://api.road.b1.app/errors/forbidden',
            'title' => 'Forbidden',
            'status' => 403,
            'detail' => 'EDUZZ_REAUTH_REQUIRED',
            'meta' => ['requestId' => 'req_x'],
        ], 403),
    ]);

    try {
        iterator_to_array(app(RoadClient::class)->me()->eduzzProducts());
        expect()->fail('expected a RoadAuthzException');
    } catch (RoadAuthzException $e) {
        // The precise Eduzz cause rides in the 7807 `detail`, so it survives on
        // the thrown exception (message) and in the raw payload.
        expect($e->getMessage())->toContain('EDUZZ_REAUTH_REQUIRED');
        expect($e->httpStatus())->toBe(403);
    }
});

it('surfaces EDUZZ_UPSTREAM_UNAVAILABLE as a 503 RoadException (C5)', function () {
    seedEduzzContext();

    Http::fake([
        'api.road.test/api/alpha/me/eduzz/products*' => Http::response([
            'type' => 'https://api.road.b1.app/errors/service-unavailable',
            'title' => 'Service Unavailable',
            'status' => 503,
            'detail' => 'EDUZZ_UPSTREAM_UNAVAILABLE',
        ], 503),
    ]);

    try {
        iterator_to_array(app(RoadClient::class)->me()->eduzzProducts());
        expect()->fail('expected a RoadException');
    } catch (RoadException $e) {
        expect($e->getMessage())->toContain('EDUZZ_UPSTREAM_UNAVAILABLE');
        expect($e->httpStatus())->toBe(503);
    }
});
