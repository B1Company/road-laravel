<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\Resources\Iam;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\CheckDecision;
use B1Road\Laravel\Exceptions\RoadApiException;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Support\Facades\Log;

/**
 * Fluent permission-check builder. Created by `Road::can(...)`. Lazy:
 * no HTTP call until `check()` / `trace()` / `result()` runs.
 *
 *   Road::can(Action::Read, Subject::Member)->in($buId)->check();
 *   Road::can('read', 'Invoice')->forPlatform()->in($buId)->check();
 *
 * The target picks the catalog. `in()` alone is the business unit, so Road's
 * catalog answers. `forPlatform()` is the unit's subscription to
 * `road.platform_id`, so your platform's catalog answers. `inScope()` names a
 * scope yourself, and that scope's catalog answers. What a code looks like
 * never changes the target: a platform may declare `manage:Role` too.
 */
final class Can
{
    private ?string $businessUnitId = null;

    private ?string $scopeId = null;

    private bool $subscription = false;

    private string $permission;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly RoadContext $context,
        Action|string $action,
        Subject|string $subject,
        private string $platformId = '',
    ) {
        $this->permission = Permission::format($action, $subject);
    }

    public function in(string $businessUnitId): self
    {
        $this->businessUnitId = $businessUnitId;
        $this->scopeId = null;

        return $this;
    }

    /** Check at a scope you already hold the id of. Its catalog decides the code. */
    public function inScope(string $scopeId): self
    {
        $this->scopeId = $scopeId;
        $this->businessUnitId = null;

        return $this;
    }

    /**
     * Check at the business unit's subscription, so the platform's catalog
     * answers. Name the unit with `in()`, before or after this. The platform
     * defaults to `road.platform_id`.
     */
    public function forPlatform(?string $platformId = null): self
    {
        $this->subscription = true;
        $this->platformId = $platformId ?? $this->platformId;

        return $this;
    }

    /**
     * Escape hatch: override the computed `action:Subject` with a raw
     * permission string, such as one built elsewhere.
     */
    public function raw(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function check(): bool
    {
        return $this->result()->allowed;
    }

    /**
     * Build a decision trace for this check. The verdict, its reason and the
     * scopes walked come from `GET /authz/checks/explain`, which only takes a
     * scope id, so a check by business unit reads that id first. The grants are
     * the caller's effective codes at the scope under a generic
     * `via: 'effective'`: they answer "what do I hold vs. what's required?"
     * without claiming which role granted what.
     */
    public function trace(): DecisionTrace
    {
        $user = $this->requireUser();
        $scopeId = $this->resolveScopeId();
        $explanation = (new Iam($this->http))->explain($scopeId, $this->permission);
        $grants = $this->effectiveGrants($scopeId);

        return DecisionTrace::fromArray([
            // Display what the caller asked about, not the scope id behind it.
            'subject' => 'user:'.$user->id,
            'scope' => (string) $this->scope(),
            'required' => [$this->permission],
            'grants' => $grants === []
                ? []
                : [['via' => 'effective', 'permissions' => $grants]],
            'verdict' => $explanation->allowed ? 'allow' : 'deny',
            'reason' => $explanation->reason,
            'evaluatedScopes' => $explanation->chain,
        ]);
    }

    public function result(): CheckDecision
    {
        $this->requireUser();

        // No subject on the wire: the API checks the caller the access token
        // names, which also keeps a service client right with no extra work.
        $address = $this->address();
        $decision = (new Iam($this->http))->check($address + ['codes' => [$this->permission]])->results[0];
        self::hintOnUnknownCode($decision, $address);

        return $decision;
    }

    /**
     * Log where to look when the target's catalog does not declare the code.
     * That verdict is the same for every caller, so it reads as a developer
     * error, and the usual cause is a check aimed at the wrong catalog. It
     * changes nothing about the decision. The API keeps the reason from a
     * caller who holds nothing on the scope's chain, so a `denied_no_grant`
     * can hide the same mistake.
     *
     * @internal
     *
     * @param  array<string,string>  $address
     */
    public static function hintOnUnknownCode(CheckDecision $decision, array $address): void
    {
        if ($decision->reason !== 'denied_unknown_code') {
            return;
        }

        [$target, $nextStep] = match (true) {
            isset($address['scopeId']) => [
                "scope {$address['scopeId']}",
                'Confirm this is the scope you meant.',
            ],
            isset($address['platformId']) => [
                "the subscription to {$address['platformId']} in business unit {$address['businessUnitId']}",
                "If the code is Road's, drop forPlatform(). If it is your platform's, declare it in your catalog.",
            ],
            default => [
                "business unit {$address['businessUnitId']}",
                "If the code is your platform's, add forPlatform().",
            ],
        };

        Log::warning("Road denied '{$decision->code}' at {$target} with denied_unknown_code: "
            ."this catalog does not declare it. The code may belong to another catalog, so check the target. {$nextStep}");
    }

    public function permissionString(): string
    {
        return $this->permission;
    }

    public function scope(): ?string
    {
        return $this->businessUnitId ?? $this->scopeId;
    }

    /**
     * How this check names its target on the wire: `scopeId`, `businessUnitId`,
     * or `businessUnitId` plus `platformId` after `forPlatform()`.
     *
     * @return array<string,string>
     */
    public function address(): array
    {
        if ($this->scopeId !== null && $this->scopeId !== '') {
            if ($this->subscription) {
                throw new \LogicException(
                    'forPlatform() and inScope() name two different targets. '
                    .'Keep inScope($scopeId) for that scope, or forPlatform()->in($buId) for the subscription.'
                );
            }

            return ['scopeId' => $this->scopeId];
        }
        if ($this->businessUnitId === null || $this->businessUnitId === '') {
            throw new \LogicException(
                'Road::can(...) requires ->in($buId) before check()/trace(). '
                .'Pass the Business Unit id you are authorizing against.'
            );
        }
        if (! $this->subscription) {
            return ['businessUnitId' => $this->businessUnitId];
        }
        if ($this->platformId === '') {
            throw new \LogicException(
                "forPlatform() checks '{$this->permission}' at a platform's subscription, and no platform id is set. "
                .'Set ROAD_PLATFORM_ID, or name it: ->forPlatform(\'plat_...\').'
            );
        }

        return ['businessUnitId' => $this->businessUnitId, 'platformId' => $this->platformId];
    }

    /** The scope id behind this check, read from the API when it was not given. */
    private function resolveScopeId(): string
    {
        $address = $this->address();
        if (isset($address['scopeId'])) {
            return $address['scopeId'];
        }

        $path = '/organization/business-units/'.rawurlencode($address['businessUnitId']);
        $key = 'iamScopeId';
        if (isset($address['platformId'])) {
            $path .= '/subscriptions/'.rawurlencode($address['platformId']);
            $key = 'scopeId';
        }

        $body = $this->http->request('GET', $path);
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $scopeId = $data[$key] ?? null;
        if (! is_string($scopeId) || $scopeId === '') {
            throw new RoadApiException("GET {$path} answered without {$key}.", 'malformed_response', 502);
        }

        return $scopeId;
    }

    /**
     * The caller's effective codes at the scope, `*` for a role that holds them
     * all. Best-effort: a failure yields no grants rather than masking the
     * verdict.
     *
     * @return list<string>
     */
    private function effectiveGrants(string $scopeId): array
    {
        try {
            $body = $this->http->request('GET', '/authz/me/permissions', null, ['scopes' => $scopeId]);
        } catch (\Throwable) {
            return [];
        }
        $rows = is_array($body['data'] ?? null) ? $body['data'] : [];
        $effective = is_array($rows[0] ?? null) ? $rows[0] : [];
        if (($effective['all'] ?? false) === true) {
            return [Permission::WILDCARD];
        }

        return array_values(array_filter((array) ($effective['codes'] ?? []), 'is_string'));
    }

    private function requireUser(): RoadUser
    {
        $user = $this->context->user();
        if ($user === null) {
            throw new RoadAuthnException(
                message: 'Cannot authorize: no current Road user on context.',
                errorCode: 'no_subject',
            );
        }

        return $user;
    }
}
