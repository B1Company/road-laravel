<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Scope;

/**
 * Get or look up a scope. Reached via `Road::client()->iam()->scopes()`. Scopes
 * are born from domain actions, such as creating a business unit, so there is
 * no create here.
 */
final class IamScopes
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

    public function get(string $scopeId): Scope
    {
        $body = $this->http->request('GET', '/authz/scopes/'.rawurlencode($scopeId));

        return Scope::from($this->unwrap($body));
    }

    /**
     * A subscription's external id repeats across business units. Send
     * `$parentScopeId` when you can read more than one match.
     */
    public function lookup(string $type, string $externalId, ?string $parentScopeId = null): Scope
    {
        $body = $this->http->request('GET', '/authz/scopes/lookup', null, array_filter([
            'type' => $type,
            'externalId' => $externalId,
            'parentScopeId' => $parentScopeId,
        ], static fn (?string $value): bool => $value !== null));

        return Scope::from($this->unwrap($body));
    }
}
