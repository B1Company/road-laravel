<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\AuthorizeResult;
use B1Road\Laravel\Exceptions\RoadAuthnException;

/**
 * Fluent permission-check builder. Created by `Road::can(...)`. Lazy:
 * no HTTP call until `check()` / `trace()` / `result()` runs.
 *
 *   Road::can(Action::Read, Subject::Member)->in($buId)->check();
 *   Road::can(Action::Update, Subject::Role)->in($buId)->trace();
 */
final class Can
{
    private ?string $scopeId = null;

    /** Cached `iamScopeId` resolved from the BU id passed to `->in()`. */
    private ?string $resolvedScope = null;

    private string $permission;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly RoadContext $context,
        Action|string $action,
        Subject|string $subject,
    ) {
        $this->permission = Permission::format($action, $subject);
    }

    public function in(string $scopeId): self
    {
        $this->scopeId = $scopeId;

        return $this;
    }

    /**
     * Escape hatch: override the computed `action:Subject` with a raw
     * permission string. Useful for platform-defined subjects outside
     * Road's core algebra (e.g. `read:CustomThing`) or the wildcard.
     */
    public function raw(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    public function check(): bool
    {
        return $this->resolve()->allowed;
    }

    /**
     * Build a decision trace for this check. The Road API does not return a
     * role-attributed decision (NFR-14 deliberately hides which role granted
     * what, and `/authorize` returns only `{ allowed, reason }`), so the trace
     * reports the caller's **effective** permissions on the scope — fetched
     * from `/iam/authorization/me/permissions?scope=` — under a generic
     * `via: 'effective'` grant. That is honest (reconstructable from the real
     * API) and still answers "what do I hold vs. what's required?".
     */
    public function trace(): DecisionTrace
    {
        $user = $this->requireUser();
        $scopeId = $this->resolvedScopeId();
        $result = $this->authorizeRaw();
        $grants = $this->effectiveGrants($scopeId);

        return DecisionTrace::fromArray([
            // Display the scope the caller asked about (the BU id), not the
            // internal IAM scope id we resolved it to.
            'subject' => 'user:'.$user->id,
            'scope' => (string) $this->scopeId,
            'required' => [$this->permission],
            'grants' => $grants === []
                ? []
                : [['via' => 'effective', 'permissions' => $grants]],
            'verdict' => ($result['allowed'] ?? false) ? 'allow' : 'deny',
            'reason' => (string) ($result['reason'] ?? ''),
            // `evaluatedScopes` (the walked scope chain) is not exposed by the
            // API, so it is intentionally omitted rather than fabricated.
        ]);
    }

    public function result(): AuthorizeResult
    {
        return $this->resolve();
    }

    public function permissionString(): string
    {
        return $this->permission;
    }

    public function scope(): ?string
    {
        return $this->scopeId;
    }

    private function resolve(): AuthorizeResult
    {
        $data = $this->authorizeRaw();

        return AuthorizeResult::from([
            'allowed' => (bool) ($data['allowed'] ?? false),
            'reason' => (string) ($data['reason'] ?? ''),
            // `evaluatedScopes` is not returned by the API (the engine emits
            // only `{ allowed, reason }`); kept for shape stability, normally
            // empty.
            'evaluatedScopes' => array_values(array_filter(
                (array) ($data['evaluatedScopes'] ?? []),
                'is_string',
            )),
        ]);
    }

    /**
     * Single authorize round-trip against the **resolved** IAM scope id.
     *
     * @return array<string,mixed> The `data` envelope: `{ allowed, reason }`.
     */
    private function authorizeRaw(): array
    {
        $this->requireUser();
        $scopeId = $this->resolvedScopeId();

        // Omit `subjectId`: the API authorizes the caller derived from the access
        // token. The BFF holds the caller's token, not their Road user id —
        // forwarding the Auth Server `sub` 403s every check, because IAM keys
        // subjects by the Road profile id, not the `sub`. The token already
        // identifies the caller. Mirrors @b1-road/nestjs.
        $body = $this->http->request('POST', '/iam/authorization/authorize', [
            'subjectType' => 'user',
            'scopeId' => $scopeId,
            'permission' => $this->permission,
        ]);

        return is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    /**
     * The BU id passed to `->in()` is an Organization-tier id, but the
     * authorization engine is keyed by the IAM scope id. Resolve it from the
     * BU detail (cached for this check), mirroring the Nest and React SDKs —
     * passing the BU id straight through denies everything against the real API.
     */
    private function resolvedScopeId(): string
    {
        $this->requireUser();
        if ($this->scopeId === null || $this->scopeId === '') {
            throw new \LogicException(
                'Road::can(...) requires ->in($buId) before check()/trace(). '
                .'Pass the Business Unit id you are authorizing against.'
            );
        }
        if ($this->resolvedScope !== null) {
            return $this->resolvedScope;
        }

        $detail = $this->http->request('GET', '/organization/business-units/'.rawurlencode($this->scopeId));
        $data = is_array($detail['data'] ?? null) ? $detail['data'] : $detail;

        return $this->resolvedScope = (string) ($data['iamScopeId'] ?? $this->scopeId);
    }

    /**
     * The caller's effective permission strings on the given scope, fetched
     * from the scope-keyed `/me/permissions?scope=` endpoint. Best-effort:
     * a failure yields no grants rather than masking the verdict.
     *
     * @return list<string>
     */
    private function effectiveGrants(string $scopeId): array
    {
        try {
            $body = $this->http->request('GET', '/iam/authorization/me/permissions', null, [
                'scope' => $scopeId,
            ]);
        } catch (\Throwable) {
            return [];
        }
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $tuples = is_array($data['permissions'] ?? null) ? $data['permissions'] : [];

        $out = [];
        foreach ($tuples as $tuple) {
            if (! is_array($tuple)) {
                continue;
            }
            $action = (string) ($tuple['action'] ?? '');
            $subject = (string) ($tuple['subject'] ?? '');
            if ($action === '') {
                continue;
            }
            $out[] = ($action === '*' && $subject === '*') ? '*' : $action.':'.$subject;
        }

        return $out;
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
