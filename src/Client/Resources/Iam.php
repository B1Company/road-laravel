<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\AuthorizeBatchResult;
use B1Road\Laravel\DTO\AuthorizeResult;

/**
 * `Road::client()->iam()->*` — the control-plane surface: authorize checks,
 * scopes, roles (via `scope()`), and assignments. The IAM tree is keyed by
 * scope id, exposed through `iam()->scope($scopeId)->roles()` so the scope id
 * is never threaded through a flat method. Mirrors `IamResource` in road-nestjs.
 */
final class Iam
{
    use UnwrapsData;

    private ?IamScopes $scopes = null;

    private ?IamAssignments $assignments = null;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /**
     * Authorize a single (subject, scope, permission). The live engine answers
     * `{ allowed, reason }` only — `evaluatedScopes` stays empty (the SDK's
     * DecisionTrace is built from effective permissions, not from here).
     *
     * @param  array<string,mixed>  $input
     */
    public function authorize(array $input): AuthorizeResult
    {
        $data = $this->unwrap($this->http->request('POST', '/iam/authorization/authorize', $input));

        $evaluated = [];
        foreach ((array) ($data['evaluatedScopes'] ?? []) as $scope) {
            if (is_string($scope)) {
                $evaluated[] = $scope;
            }
        }

        return new AuthorizeResult(
            allowed: (bool) ($data['allowed'] ?? false),
            reason: (string) ($data['reason'] ?? ''),
            evaluatedScopes: $evaluated,
        );
    }

    /** @param  array<string,mixed>  $input */
    public function authorizeBatch(array $input): AuthorizeBatchResult
    {
        return AuthorizeBatchResult::from(
            $this->unwrap($this->http->request('POST', '/iam/authorization/authorize/batch', $input)),
        );
    }

    public function scope(string $scopeId): IamScope
    {
        return new IamScope($this->http, $scopeId);
    }

    public function scopes(): IamScopes
    {
        return $this->scopes ??= new IamScopes($this->http);
    }

    public function assignments(): IamAssignments
    {
        return $this->assignments ??= new IamAssignments($this->http);
    }
}
