<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\AuthServer\TokenSet;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function seedSessionTokens(string $token = 'sess-bearer-123'): void
{
    /** @var TokenStore $store */
    $store = app(TokenStore::class);
    $store->put(new TokenSet(
        accessToken: $token,
        refreshToken: 'rt',
        idToken: null,
        expiresAt: time() + 3600,
        userPayload: [
            'sub' => 'u_proxy',
            'email' => 'p@example.com',
            'name' => 'Proxy User',
        ],
    ));
}

it('forwards an allow-listed path with the session Bearer attached', function () {
    seedSessionTokens();

    Http::fake([
        'api.road.test/organization/business-units/bu_1' => Http::response(
            ['data' => ['id' => 'bu_1', 'name' => 'B1']],
            200,
        ),
    ]);

    $response = $this->getJson('/road-api/organization/business-units/bu_1');

    $response->assertOk()->assertJsonPath('data.id', 'bu_1');

    Http::assertSent(function (HttpRequest $req) {
        return $req->hasHeader('Authorization', 'Bearer sess-bearer-123')
            && str_starts_with($req->url(), 'https://api.road.test/organization/business-units/bu_1');
    });
});

it('404s a path outside the proxy allowlist without calling upstream', function () {
    seedSessionTokens();

    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $response = $this->getJson('/road-api/private/secrets');

    $response->assertStatus(404)->assertJsonPath('error.code', 'not_found');

    Http::assertNothingSent();
});

it('401s when no session token is present', function () {
    Http::fake([
        '*' => Http::response(['ok' => true], 200),
    ]);

    $response = $this->getJson('/road-api/organization/business-units/bu_1');

    $response->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
    Http::assertNothingSent();
});

it('forwards POST bodies and content types intact', function () {
    seedSessionTokens();

    Http::fake([
        'api.road.test/organization/business-units' => Http::response(
            ['data' => ['id' => 'bu_new']],
            201,
        ),
    ]);

    $response = $this->postJson('/road-api/organization/business-units', [
        'name' => 'My BU',
        'slug' => 'my-bu',
    ]);

    $response->assertStatus(201)->assertJsonPath('data.id', 'bu_new');

    Http::assertSent(function (HttpRequest $req) {
        $body = (string) $req->body();

        return $req->method() === 'POST'
            && str_contains($body, 'My BU')
            && str_contains($body, 'my-bu');
    });
});

it('passes upstream non-2xx status through unchanged', function () {
    seedSessionTokens();

    Http::fake([
        'api.road.test/iam/identity/me' => Http::response(
            ['error' => ['code' => 'not_found', 'message' => 'gone']],
            404,
        ),
    ]);

    $response = $this->getJson('/road-api/iam/identity/me');

    $response
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});
