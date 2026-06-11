<?php

declare(strict_types=1);

use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Facades\Road;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/** @return array<string,mixed> */
function discoveryDoc(): array
{
    return [
        'issuer' => 'https://auth.test',
        'authorization_endpoint' => 'https://auth.test/oauth/v2/authorize',
        'token_endpoint' => 'https://auth.test/oauth/v2/token',
        'jwks_uri' => 'https://auth.test/oauth/v2/keys',
    ];
}

/** @return array<string,mixed> */
function serviceBuDetail(string $id, string $scopeId): array
{
    return ['id' => $id, 'name' => 'BU '.$id, 'slug' => $id, 'status' => 'active', 'memberCount' => 1, 'memberLimit' => null, 'joinCode' => null, 'createdAt' => '2024-01-01T00:00:00Z', 'iamScopeId' => $scopeId];
}

function configureClientCredentials(): void
{
    config([
        'road.service.mode' => 'client_credentials',
        'road.service.client_id' => 'svc-client',
        'road.service.client_secret' => 'svc-secret',
        'road.service.audience' => 'road-api',
    ]);
}

it('throws a clear error when asService() has no credentials configured', function () {
    config(['road.service.client_id' => null, 'road.service.client_secret' => null]);

    expect(fn () => Road::asService())->toThrow(RoadAuthnException::class);
});

it('acquires a client_credentials token and attaches it as the Bearer', function () {
    configureClientCredentials();
    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response(discoveryDoc()),
        'auth.test/oauth/v2/token' => Http::response(['access_token' => 'svc-token', 'expires_in' => 3600, 'token_type' => 'Bearer']),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => serviceBuDetail('bu_1', 'scope_1')]),
    ]);

    $bu = Road::asService()->client()->businessUnits('bu_1')->fetch();

    expect($bu->id)->toBe('bu_1');
    // Token endpoint hit with the client_credentials grant + secret.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/oauth/v2/token')
        && ($req->data()['grant_type'] ?? null) === 'client_credentials'
        && ($req->data()['client_secret'] ?? null) === 'svc-secret');
    // Road API call carries the acquired service token — not a user token.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/business-units/bu_1')
        && $req->hasHeader('Authorization', 'Bearer svc-token'));
});

it('caches the service token across calls', function () {
    configureClientCredentials();
    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response(discoveryDoc()),
        'auth.test/oauth/v2/token' => Http::response(['access_token' => 'svc-token', 'expires_in' => 3600]),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => serviceBuDetail('bu_1', 'scope_1')]),
    ]);

    $service = Road::asService();
    $service->client()->businessUnits('bu_1')->fetch();
    $service->client()->businessUnits('bu_1')->fetch();

    $tokenCalls = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/oauth/v2/token'))
        ->count();
    expect($tokenCalls)->toBe(1); // acquired once, then served from cache
});

it('re-acquires and retries once on a 401 from Road', function () {
    configureClientCredentials();
    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response(discoveryDoc()),
        'auth.test/oauth/v2/token' => Http::sequence()
            ->push(['access_token' => 'stale-token', 'expires_in' => 3600])
            ->push(['access_token' => 'fresh-token', 'expires_in' => 3600]),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::sequence()
            ->push(['type' => 'https://api.road.b1.app/errors/authentication-required', 'title' => 'Unauthorized', 'status' => 401, 'detail' => 'token rejected'], 401)
            ->push(['data' => serviceBuDetail('bu_1', 'scope_1')], 200),
    ]);

    $bu = Road::asService()->client()->businessUnits('bu_1')->fetch();

    expect($bu->id)->toBe('bu_1');
    $tokenCalls = collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/oauth/v2/token'))
        ->count();
    expect($tokenCalls)->toBe(2); // initial + re-acquire after the 401
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/business-units/bu_1')
        && $req->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('signs a private_key_jwt client assertion', function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    config([
        'road.service.mode' => 'private_key_jwt',
        'road.service.client_id' => 'svc-client',
        'road.service.key_id' => 'svc-key-1',
        'road.service.private_key' => $pem,
        'road.service.audience' => 'road-api',
    ]);

    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response(discoveryDoc()),
        'auth.test/oauth/v2/token' => Http::response(['access_token' => 'svc-token', 'expires_in' => 3600]),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => serviceBuDetail('bu_1', 'scope_1')]),
    ]);

    Road::asService()->client()->businessUnits('bu_1')->fetch();

    Http::assertSent(function (HttpRequest $req) {
        if (! str_contains($req->url(), '/oauth/v2/token')) {
            return false;
        }
        $data = $req->data();

        return ($data['client_assertion_type'] ?? null) === 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer'
            && is_string($data['client_assertion'] ?? null)
            && substr_count((string) $data['client_assertion'], '.') === 2 // header.payload.signature
            && ! isset($data['client_secret']);
    });
});
