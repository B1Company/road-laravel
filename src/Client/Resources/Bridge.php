<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Bridge\BridgeContext;
use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\Generated\BridgeAttemptDto;
use B1Road\Laravel\DTO\Generated\BridgeAuthorizeDto;
use B1Road\Laravel\Exceptions\RoadBridgeExchangeException;
use B1Road\Laravel\Exceptions\RoadBridgeSetupException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadRateLimitException;
use Closure;
use InvalidArgumentException;

/**
 * Platform Bridge, both sides. Mirrors `road.bridge` in `@b1-road/node-core`.
 *
 * **As a consumer**, `Road::client()->bridge()->exchangeForUser()` inside a
 * `road`-protected route gets a token for the provider, acting for the
 * signed-in person.
 *
 * **As the provider**, call `authorize()` with your platform's own service
 * credential (`Road::asService()->client()->bridge()`) and pass the token a
 * consumer presented to you; Road matches that token's audience to your
 * platform, so you can only ever ask about tokens minted for you. Prefer the
 * `road.bridge` middleware over calling it directly. It owns the caching,
 * invalidation, fail mode and tenant/acting-user checks you would otherwise
 * reimplement.
 *
 * `audit()` is the platform owner's read of its Bridge traffic, so it needs
 * the owner's user token (`Road::client()` where the owner is signed in),
 * never the service credential.
 */
final class Bridge
{
    use UnwrapsData;

    /** @param  (Closure(): ?HttpTransport)|null  $serviceTransport */
    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly ?RoadContext $context = null,
        private readonly ?Closure $serviceTransport = null,
        private readonly string $platformId = '',
    ) {}

    /**
     * Ask Road to attest that the signed-in person is an active member of the
     * business unit, for your platform. Step 1 of a Bridge call; send the
     * result as `presence_assertion` on the exchange. Runs as the person, so
     * call it on `Road::client()` inside a `road`-protected route.
     *
     * @return array{presenceAssertion: string, expiresIn: int}
     */
    public function presenceAssertion(string $businessUnitId, ?string $platformId = null): array
    {
        $platformId = $this->platformIdFor($platformId, 'presenceAssertion');
        $this->requirePerson('presenceAssertion');

        $data = $this->unwrap($this->http->request('POST', '/bridge/presence-assertions', [
            'businessUnitId' => $businessUnitId,
            'consumerPlatformPublicId' => $platformId,
        ]));

        return [
            'presenceAssertion' => (string) ($data['presenceAssertion'] ?? ''),
            'expiresIn' => (int) ($data['expiresIn'] ?? 0),
        ];
    }

    /**
     * A token audienced at the provider, for the signed-in person in one
     * business unit: the presence assertion with the person's session, then
     * the RFC 8693 exchange with your platform's service credential
     * (`ROAD_SERVICE_CLIENT_ID` / `ROAD_SERVICE_CLIENT_SECRET`).
     *
     * Returns the raw OAuth payload (`access_token`, `expires_in`, …). A refusal
     * throws {@see RoadBridgeExchangeException} carrying Road's code; a missing
     * credential, platform id or person throws {@see RoadBridgeSetupException}
     * before anything is sent.
     *
     * @param  string|list<string>  $scope  Permission codes (`read:Task`), never a template name.
     * @return array<string,mixed>
     */
    public function exchangeForUser(string $audience, string|array $scope, string $businessUnitId, ?string $platformId = null): array
    {
        $service = $this->serviceTransport !== null ? ($this->serviceTransport)() : null;
        if ($service === null) {
            throw new RoadBridgeSetupException(
                "bridge()->exchangeForUser() exchanges your platform's service credential, and none is configured. Set ROAD_SERVICE_CLIENT_ID and ROAD_SERVICE_CLIENT_SECRET (road_issue_service_credential writes both to your .env).",
                'service_credentials_missing',
            );
        }
        $this->platformIdFor($platformId, 'exchangeForUser');
        $assertion = $this->presenceAssertion($businessUnitId, $platformId);

        $send = function (bool $fresh) use ($service, $audience, $scope, $businessUnitId, $assertion): array {
            $token = (string) $service->serviceToken($fresh);

            return $service->bearing($token)->request('POST', '/bridge/token-exchange', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $token,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:access_token',
                'audience' => $audience,
                'scope' => is_array($scope) ? implode(' ', $scope) : $scope,
                'business_unit' => $businessUnitId,
                'presence_assertion' => $assertion['presenceAssertion'],
            ]);
        };

        try {
            return $send(false);
        } catch (RoadException $e) {
            $refusal = self::asExchangeRefusal($e);
            if ($refusal?->errorCode() !== 'invalid_token') {
                throw $refusal ?? $e;
            }
        }

        // A rotated or expired service token: once more, with a fresh one.
        try {
            return $send(true);
        } catch (RoadException $e) {
            throw self::asExchangeRefusal($e) ?? $e;
        }
    }

    /**
     * What a brokered token is authorized to do, recomputed live by Road.
     *
     * Omit `$permissions` for the full context; pass them to ask about those
     * codes only (the one question Road still answers for a degraded token).
     *
     * @param  list<string>|null  $permissions
     */
    public function authorize(string $brokeredToken, ?array $permissions = null): BridgeContext
    {
        $body = self::withoutNulls((new BridgeAuthorizeDto(brokeredToken: $brokeredToken, permissions: $permissions))->toArray());

        return BridgeContext::fromWire($this->unwrap($this->http->request('POST', '/bridge/authorize', $body)));
    }

    /**
     * Tell Road what a brokered token was actually used for. Without it the
     * audit trail shows what was issued and never what was attempted.
     */
    public function reportAttempt(
        string $brokeredToken,
        string $permission,
        bool $allowed,
        ?string $method = null,
        ?string $path = null,
    ): void {
        // Named, never positional: the generated DTO's constructor follows the
        // contract hub's field order, and a new optional field (`reason`) once
        // landed before `method`, shifting every positional argument after it.
        $dto = new BridgeAttemptDto(
            brokeredToken: $brokeredToken,
            permission: $permission,
            allowed: $allowed,
            method: $method,
            path: $path,
        );
        $this->http->request('POST', '/bridge/authorize/attempts', self::withoutNulls($dto->toArray()));
    }

    private function platformIdFor(?string $platformId, string $method): string
    {
        $platformId = ($platformId ?? '') !== '' ? (string) $platformId : $this->platformId;
        if ($platformId === '') {
            throw new RoadBridgeSetupException(
                "bridge()->{$method}() binds the assertion to your platform, and no platform id is configured. Set ROAD_PLATFORM_ID (plat_…), or pass it on this call.",
                'platform_id_missing',
            );
        }

        return $platformId;
    }

    private function requirePerson(string $method): void
    {
        // A hand-built client carries no context; Road answers for it instead.
        if ($this->context === null) {
            return;
        }
        if ($this->context->isServiceMode() || ($this->context->token() ?? '') === '') {
            throw new RoadBridgeSetupException(
                "bridge()->{$method}() acts as the signed-in person, and this client has no person behind it: it calls Road with the service credential, or runs outside a `road`-protected route. Call it on Road::client() inside one.",
                'person_required',
            );
        }
    }

    /**
     * The exchange refuses in the OAuth shape, `{ error, error_description }`,
     * which the transport maps by status alone. Re-read the body so the code
     * survives; anything else (a network failure) is rethrown as it came.
     */
    private static function asExchangeRefusal(RoadException $e): ?RoadBridgeExchangeException
    {
        $payload = $e->payload();
        $error = $payload['error'] ?? null;
        if (! is_string($error) || $error === '') {
            return null;
        }
        $description = $payload['error_description'] ?? null;

        return new RoadBridgeExchangeException(
            errorCode: $error,
            description: is_string($description) ? $description : '',
            status: $e->httpStatus(),
            retryAfter: $e instanceof RoadRateLimitException ? $e->retryAfter : null,
            requestId: $e->requestId(),
            payload: $payload,
            previous: $e,
        );
    }

    /**
     * Your platform's Bridge audit: the token exchanges, checks, attempts and
     * grant changes it took part in. `inbound` is other platforms reaching yours
     * (you are the provider); `outbound` is yours reaching others (you are the
     * consumer). Pass `$allowed: false` for refusals only.
     *
     *   foreach (Road::client()->bridge()->audit('plat_…', 'inbound') as $row) { ... }
     *
     * **Call it as the platform's owner** (`Road::client()` on a request where
     * the owner is signed in). The service credential gets 403, and so does a
     * platform you do not own.
     */
    public function audit(string $platformId, string $direction, ?bool $allowed = null): BridgeAuditCollection
    {
        if (! in_array($direction, ['inbound', 'outbound'], true)) {
            throw new InvalidArgumentException(
                "Unknown Bridge audit direction '{$direction}'. Use 'inbound' (others reaching your platform) or 'outbound' (yours reaching others).",
            );
        }

        return new BridgeAuditCollection($this->http, $platformId, $direction, $allowed);
    }

    /**
     * An absent optional field and a `null` one are not the same question to
     * Road: `permissions: null` must read as list mode, and the safest way to
     * say that is not to send the key.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private static function withoutNulls(array $body): array
    {
        return array_filter($body, static fn (mixed $v): bool => $v !== null);
    }
}
