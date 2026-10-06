<?php

declare(strict_types=1);

use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadBridgeExchangeException;
use B1Road\Laravel\Exceptions\RoadBridgeSetupException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Facades\Road;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

/*
 * bridge()->presenceAssertion() / exchangeForUser() — the consumer side of an
 * attended Bridge call (F4.1 in plan 68). The assertions are about which
 * credential went where and which code came back.
 */

const BRIDGE_PRESENCE_URL = 'api.road.test/api/alpha/bridge/presence-assertions';
const BRIDGE_EXCHANGE_URL = 'api.road.test/api/alpha/bridge/token-exchange';

function bridgeConsumerSetup(bool $service = true, ?string $platformId = 'plat_me'): void
{
    config([
        'road.platform_id' => $platformId,
        'road.service.mode' => 'client_credentials',
        'road.service.client_id' => $service ? 'svc-client' : null,
        'road.service.client_secret' => $service ? 'svc-secret' : null,
    ]);
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setToken('person-token');
}

/** @param  array<string,mixed>  $exchange */
function bridgeConsumerFake(array|Closure $exchange): void
{
    Http::fake([
        'auth.test/.well-known/openid-configuration' => Http::response([
            'issuer' => 'https://auth.test',
            'authorization_endpoint' => 'https://auth.test/oauth/v2/authorize',
            'token_endpoint' => 'https://auth.test/oauth/v2/token',
            'jwks_uri' => 'https://auth.test/oauth/v2/keys',
        ]),
        'auth.test/oauth/v2/token' => Http::sequence()
            ->push(['access_token' => 'svc-1', 'expires_in' => 3600])
            ->push(['access_token' => 'svc-2', 'expires_in' => 3600])
            ->push(['access_token' => 'svc-3', 'expires_in' => 3600]),
        BRIDGE_PRESENCE_URL => Http::response(['data' => ['presenceAssertion' => 'pa-jwt', 'expiresIn' => 300]]),
        BRIDGE_EXCHANGE_URL => is_array($exchange) ? Http::response($exchange) : $exchange,
    ]);
}

/** @return list<HttpRequest> */
function bridgeConsumerSent(string $url): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (HttpRequest $req) => str_contains($req->url(), $url))
        ->values()
        ->all();
}

it('asserts presence as the person, then exchanges the service credential', function () {
    bridgeConsumerSetup();
    bridgeConsumerFake(['access_token' => 'brokered', 'token_type' => 'Bearer', 'expires_in' => 43200]);

    $token = Road::client()->bridge()->exchangeForUser('plat_provider', ['read:Task', 'update:Task'], 'bu_1');

    expect($token['access_token'])->toBe('brokered');

    [$presence] = bridgeConsumerSent(BRIDGE_PRESENCE_URL);
    expect($presence->hasHeader('Authorization', 'Bearer person-token'))->toBeTrue()
        ->and($presence->data())->toBe(['businessUnitId' => 'bu_1', 'consumerPlatformPublicId' => 'plat_me']);

    [$exchange] = bridgeConsumerSent(BRIDGE_EXCHANGE_URL);
    // The person's token never reaches the exchange, as bearer or as subject.
    expect($exchange->hasHeader('Authorization', 'Bearer svc-1'))->toBeTrue()
        ->and($exchange->data())->toBe([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => 'svc-1',
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
            'audience' => 'plat_provider',
            'scope' => 'read:Task update:Task',
            'business_unit' => 'bu_1',
            'presence_assertion' => 'pa-jwt',
        ]);
});

it('surfaces each exchange refusal typed, carrying the code', function (string $code, int $status) {
    bridgeConsumerSetup();
    bridgeConsumerFake(fn () => Http::response(
        ['error' => $code, 'error_description' => "described {$code}"],
        $status,
        $code === 'RATE_LIMITED' ? ['Retry-After' => '7'] : [],
    ));

    try {
        Road::client()->bridge()->exchangeForUser('plat_provider', 'read:Task', 'bu_1');
        test()->fail('expected a refusal');
    } catch (RoadBridgeExchangeException $e) {
        expect($e->errorCode())->toBe($code)
            ->and($e->httpStatus())->toBe($status)
            ->and($e->description)->toBe("described {$code}")
            ->and($e->retryAfter)->toBe($code === 'RATE_LIMITED' ? 7 : null)
            ->and($e->getMessage())->not->toContain('svc-')
            ->and($e->getMessage())->not->toContain('person-token');
    }
    // Only invalid_token earns the one retry. (5xx follows the transport's
    // transient-retry policy, like every other Road call.)
    if ($status < 500) {
        expect(bridgeConsumerSent(BRIDGE_EXCHANGE_URL))->toHaveCount($code === 'invalid_token' ? 2 : 1);
    }
})->with([
    ['invalid_request', 400],
    ['invalid_scope', 400],
    ['invalid_token', 401],
    ['INVALID_PRESENCE_ASSERTION', 403],
    ['BU_NOT_SUBSCRIBED_TO_PLATFORM', 403],
    ['NOT_A_BUSINESS_UNIT_MEMBER', 403],
    ['TENANT_NOT_AUTHORIZED', 403],
    ['TOKEN_EXCHANGE_DENIED', 403],
    ['PLATFORM_NOT_FOUND', 404],
    ['PLATFORM_NOT_HOMOLOGATED', 409],
    ['PROVIDER_AUTHORIZATION_NOT_ATTESTED', 409],
    ['RATE_LIMITED', 429],
    ['BRIDGE_MINT_NOT_CONFIGURED', 503],
    ['UPSTREAM_UNAVAILABLE', 503],
]);

it('retries once with a fresh service token on invalid_token', function () {
    bridgeConsumerSetup();
    bridgeConsumerFake(fn (HttpRequest $req) => ($req->data()['subject_token'] ?? null) === 'svc-1'
        ? Http::response(['error' => 'invalid_token', 'error_description' => 'expired'], 401)
        : Http::response(['access_token' => 'brokered', 'token_type' => 'Bearer', 'expires_in' => 60]));

    $token = Road::client()->bridge()->exchangeForUser('plat_provider', 'read:Task', 'bu_1');

    expect($token['access_token'])->toBe('brokered');
    $exchanges = bridgeConsumerSent(BRIDGE_EXCHANGE_URL);
    expect(array_map(fn (HttpRequest $r) => $r->data()['subject_token'], $exchanges))->toBe(['svc-1', 'svc-2'])
        ->and($exchanges[1]->hasHeader('Authorization', 'Bearer svc-2'))->toBeTrue();
});

it('rethrows a failure that is not an OAuth refusal, never swallows it', function () {
    bridgeConsumerSetup();
    config(['road.api.retry.enabled' => false]);
    bridgeConsumerFake(fn () => throw new ConnectionException('connection refused'));

    expect(fn () => Road::client()->bridge()->exchangeForUser('plat_provider', 'read:Task', 'bu_1'))
        ->toThrow(RoadNetworkException::class);
});

it('refuses up front, naming the variables, when no service credential is configured', function () {
    bridgeConsumerSetup(service: false);
    bridgeConsumerFake(['access_token' => 'brokered']);

    try {
        Road::client()->bridge()->exchangeForUser('plat_provider', 'read:Task', 'bu_1');
        test()->fail('expected a setup error');
    } catch (RoadBridgeSetupException $e) {
        expect($e->errorCode())->toBe('service_credentials_missing')
            ->and($e->getMessage())->toContain('ROAD_SERVICE_CLIENT_ID')
            ->and($e->getMessage())->toContain('ROAD_SERVICE_CLIENT_SECRET')
            ->and($e->httpStatus())->toBe(500);
    }
    Http::assertNothingSent();
});

it('refuses up front when no platform id is configured', function () {
    bridgeConsumerSetup(platformId: null);
    bridgeConsumerFake(['access_token' => 'brokered']);

    expect(fn () => Road::client()->bridge()->presenceAssertion('bu_1'))
        ->toThrow(RoadBridgeSetupException::class, 'ROAD_PLATFORM_ID');
    Http::assertNothingSent();
});

it('binds the assertion to a per-call platform id over the configured one', function () {
    bridgeConsumerSetup();
    bridgeConsumerFake(['access_token' => 'brokered']);

    $assertion = Road::client()->bridge()->presenceAssertion('bu_1', 'plat_other');

    expect($assertion)->toBe(['presenceAssertion' => 'pa-jwt', 'expiresIn' => 300])
        ->and(bridgeConsumerSent(BRIDGE_PRESENCE_URL)[0]->data()['consumerPlatformPublicId'])->toBe('plat_other');
});

it('refuses a presence assertion from a client with no person behind it', function () {
    bridgeConsumerSetup();
    bridgeConsumerFake(['access_token' => 'brokered']);

    try {
        Road::asService()->client()->bridge()->presenceAssertion('bu_1');
        test()->fail('expected a setup error');
    } catch (RoadBridgeSetupException $e) {
        expect($e->errorCode())->toBe('person_required')
            ->and($e->getMessage())->toContain('Road::client()');
    }
    expect(bridgeConsumerSent(BRIDGE_PRESENCE_URL))->toBe([]);

    // Outside a `road`-protected route the request context holds no token.
    app(RoadContext::class)->setToken(null);
    expect(fn () => Road::client()->bridge()->presenceAssertion('bu_1'))
        ->toThrow(RoadBridgeSetupException::class, 'no person behind it');
});
