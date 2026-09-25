<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Bridge\BridgeContext;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Generated\BridgeAttemptDto;
use B1Road\Laravel\DTO\Generated\BridgeAuthorizeDto;

/**
 * `Road::asService()->client()->bridge()` — the provider side of Platform
 * Bridge. Mirrors `road.bridge.authorize` / `road.bridge.reportAttempt` in
 * `@b1-road/node-core`.
 *
 * **You are the provider here.** Call it with your platform's own service
 * credential and pass the token a consumer presented to you; Road matches
 * that token's audience to your platform, so you can only ever ask about
 * tokens minted for you.
 *
 * Prefer the `road.bridge` middleware over calling this directly. It owns the
 * caching, invalidation, fail mode and tenant/acting-user checks you would
 * otherwise reimplement.
 */
final class Bridge
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

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
        $body = self::withoutNulls((new BridgeAuthorizeDto($brokeredToken, $permissions))->toArray());

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
        $dto = new BridgeAttemptDto($brokeredToken, $permission, $allowed, $method, $path);
        $this->http->request('POST', '/bridge/authorize/attempts', self::withoutNulls($dto->toArray()));
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
