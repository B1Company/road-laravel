<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Facades\Road;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/**
 * The escape hatches (P7): a raw request() for unmodelled endpoints, and
 * asUser($token) to act as a user whose token you already hold. Both mirror
 *
 * @b1-road/nestjs (request<T>() and road.as.user(token)).
 */
function seedHatchContext(): void
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: 'u_1', email: 'u@u.com', name: 'U'));
    $ctx->setToken('user-token');
    $ctx->setRequestId('req_1');
}

it('request() hits an unmodelled endpoint and returns the decoded body', function () {
    seedHatchContext();
    Http::fake([
        'api.road.test/api/alpha/some/new/endpoint*' => Http::response(['data' => ['ok' => true], 'extra' => 1], 200),
    ]);

    $body = app(RoadClient::class)->request('GET', '/some/new/endpoint', query: ['a' => 'b']);

    // The full body is returned (envelope not unwrapped).
    expect($body)->toBe(['data' => ['ok' => true], 'extra' => 1]);
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/some/new/endpoint')
        && str_contains($req->url(), 'a=b'));
});

it('exposes the underlying transport', function () {
    expect(app(RoadClient::class)->transport())->toBeInstanceOf(HttpTransportInterface::class);
});

it('asUser($token) attaches the given token as the Bearer', function () {
    Http::fake([
        'api.road.test/api/alpha/iam/identity/me' => Http::response([
            'data' => ['id' => 'u_9', 'name' => 'Nine', 'email' => 'n@n.com'],
        ], 200),
    ]);

    $me = Road::asUser('handheld-token-123')->client()->me()->get();

    expect($me->id)->toBe('u_9');
    Http::assertSent(fn (HttpRequest $req) => $req->hasHeader('Authorization', 'Bearer handheld-token-123'));
});
