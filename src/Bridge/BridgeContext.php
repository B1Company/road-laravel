<?php

declare(strict_types=1);

namespace B1Road\Laravel\Bridge;

use Illuminate\Http\Request;
use UnexpectedValueException;

/**
 * What a brokered token is authorized to do. Wire shape of `@b1-road/types`
 * `BridgeAuthorizeResult`, plus `servedStale`.
 *
 * The `road.bridge` middleware attaches one to every request it lets through:
 *
 *   $bridge = BridgeContext::of($request);
 *   $bridge->businessUnitId;   // the tenant the token is scoped to, if any
 *   $bridge->can('delete:Charge');
 */
final class BridgeContext
{
    /** The request attribute the middleware stores the context under. */
    public const ATTRIBUTE = 'roadBridge';

    /**
     * @param  list<string>  $permissions
     * @param  list<array{permission: string, allowed: bool}>|null  $decisions
     */
    public function __construct(
        /** Which exchange minted the token: `bridge` or `extensions`. Do not branch tenancy on it. */
        public readonly string $leg,
        /** The consumer, in Road's subject namespace. */
        public readonly string $subjectId,
        /** Your platform's `publicId`. */
        public readonly string $providerPublicId,
        /** The permission codes this token is authorized for right now. */
        public readonly array $permissions,
        public readonly ?array $decisions = null,
        /** Extensions only: the install (`exti_…`) the token was minted for. */
        public readonly ?string $install = null,
        /** The business unit this token may read. Absent means the token names no tenant. */
        public readonly ?string $businessUnitId = null,
        /** The end-user the call acts for, when one was proven present. */
        public readonly ?string $onBehalfOfUser = null,
        /** Epoch seconds; mirrors the token's own expiry. */
        public readonly ?int $expiresAt = null,
        /** Road rebuilt this answer from the token, not its mint record. Weaker. */
        public readonly bool $degraded = false,
        /** Served from cache because Road could not be reached (bounded by `max_staleness`). */
        public readonly bool $servedStale = false,
    ) {}

    /** The context the `road.bridge` middleware attached, or null on a route it does not guard. */
    public static function of(Request $request): ?self
    {
        $context = $request->attributes->get(self::ATTRIBUTE);

        return $context instanceof self ? $context : null;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * Build from Road's `data` payload. Refuses a body missing the fields an
     * enforcement decision reads, so a malformed answer can never become an
     * allow by defaulting to something permissive.
     *
     * @param  array<string,mixed>  $data
     */
    public static function fromWire(array $data): self
    {
        $leg = $data['leg'] ?? null;
        $permissions = $data['permissions'] ?? null;
        if (! is_string($leg) || ! is_array($permissions)) {
            throw new UnexpectedValueException('Road returned a Bridge authorization context without `leg` or `permissions`.');
        }

        $decisions = null;
        if (is_array($data['decisions'] ?? null)) {
            $decisions = [];
            foreach ($data['decisions'] as $row) {
                if (is_array($row) && is_string($row['permission'] ?? null)) {
                    $decisions[] = ['permission' => $row['permission'], 'allowed' => ($row['allowed'] ?? false) === true];
                }
            }
        }

        return new self(
            leg: $leg,
            subjectId: self::str($data['subjectId'] ?? null) ?? '',
            providerPublicId: self::str($data['providerPublicId'] ?? null) ?? '',
            permissions: array_values(array_filter($permissions, 'is_string')),
            decisions: $decisions,
            install: self::str($data['install'] ?? null),
            businessUnitId: self::str($data['businessUnitId'] ?? null),
            onBehalfOfUser: self::str($data['onBehalfOfUser'] ?? null),
            expiresAt: is_int($data['expiresAt'] ?? null) ? $data['expiresAt'] : null,
            degraded: ($data['degraded'] ?? false) === true,
        );
    }

    /** @return array<string,mixed> */
    public function toWire(): array
    {
        return array_filter([
            'leg' => $this->leg,
            'subjectId' => $this->subjectId,
            'providerPublicId' => $this->providerPublicId,
            'permissions' => $this->permissions,
            'decisions' => $this->decisions,
            'install' => $this->install,
            'businessUnitId' => $this->businessUnitId,
            'onBehalfOfUser' => $this->onBehalfOfUser,
            'expiresAt' => $this->expiresAt,
            'degraded' => $this->degraded ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function withServedStale(): self
    {
        return new self(
            $this->leg, $this->subjectId, $this->providerPublicId, $this->permissions, $this->decisions,
            $this->install, $this->businessUnitId, $this->onBehalfOfUser, $this->expiresAt, $this->degraded,
            servedStale: true,
        );
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
