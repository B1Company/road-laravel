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

/**
 * Fluent permission-check builder. Created by `Road::can(...)`. Lazy:
 * no HTTP call until `check()` / `trace()` / `result()` runs.
 *
 *   Road::can(Action::Read, Subject::Member)->in($buId)->check();
 *   Road::can('read', 'Invoice')->in($buId)->check();
 *
 * The code decides whose catalog answers. A {@see Subject} is Road's, so it is
 * checked at the business unit. Any other subject is your platform's, so it is
 * checked at the unit's subscription to `road.platform_id`. Two overrides:
 * `forPlatform()` for your own code that shares a name with Road's, `inScope()`
 * to name a scope yourself.
 */
final class Can
{
    private ?string $businessUnitId = null;

    private ?string $scopeId = null;

    private bool $platformCode = false;

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
     * Check in a platform's catalog even when the code reads like Road's, as
     * `manage:Role` does. Defaults to `road.platform_id`.
     */
    public function forPlatform(?string $platformId = null): self
    {
        $this->platformCode = true;
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
        return (new Iam($this->http))->check($this->address() + ['codes' => [$this->permission]])->results[0];
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
     * How this check names its scope on the wire: `scopeId`, `businessUnitId`,
     * or `businessUnitId` plus `platformId`.
     *
     * @return array<string,string>
     */
    public function address(): array
    {
        if ($this->scopeId !== null && $this->scopeId !== '') {
            return ['scopeId' => $this->scopeId];
        }
        if ($this->businessUnitId === null || $this->businessUnitId === '') {
            throw new \LogicException(
                'Road::can(...) requires ->in($buId) before check()/trace(). '
                .'Pass the Business Unit id you are authorizing against.'
            );
        }
        if (! $this->platformCode && Permission::isRoadCode($this->permission)) {
            return ['businessUnitId' => $this->businessUnitId];
        }
        if ($this->platformId === '') {
            throw new \LogicException(
                "'{$this->permission}' is not a Road code, so it is checked in your platform's catalog. "
                .'Set ROAD_PLATFORM_ID, or call ->forPlatform($platformId).'
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
