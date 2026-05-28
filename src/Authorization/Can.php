<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

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

    private string $permission;

    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly RoadContext $context,
        Action $action,
        Subject $subject,
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

    public function trace(): DecisionTrace
    {
        $body = $this->http->request('POST', '/iam/authorization/authorize', $this->payload(debug: true));
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $trace = is_array($data['decision'] ?? null) ? $data['decision'] : $data;

        return DecisionTrace::fromArray($trace);
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
        $body = $this->http->request('POST', '/iam/authorization/authorize', $this->payload());
        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;

        return AuthorizeResult::from([
            'allowed'         => (bool) ($data['allowed'] ?? false),
            'reason'          => (string) ($data['reason'] ?? ''),
            'evaluatedScopes' => array_values(array_filter(
                (array) ($data['evaluatedScopes'] ?? []),
                'is_string',
            )),
        ]);
    }

    /** @return array<string,mixed> */
    private function payload(bool $debug = false): array
    {
        $user = $this->context->user();
        if ($user === null) {
            throw new RoadAuthnException(
                message: 'Cannot authorize: no current Road user on context.',
                errorCode: 'no_subject',
            );
        }
        if ($this->scopeId === null || $this->scopeId === '') {
            throw new \LogicException(
                'Road::can(...) requires ->in($scopeId) before check()/trace(). '
                .'Pass the Business Unit id (or other scope id) you are authorizing against.'
            );
        }

        $payload = [
            'subjectType' => 'user',
            'subjectId'   => $user->id,
            'scopeId'     => $this->scopeId,
            'permission'  => $this->permission,
        ];

        if ($debug) {
            $payload['debug'] = true;
        }

        return $payload;
    }
}
