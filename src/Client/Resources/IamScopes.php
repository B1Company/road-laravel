<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Scope;

/**
 * Create / get / lookup IAM scopes. Reached via
 * `Road::client()->iam()->scopes()`. Mirrors the `iam.scopes` object in
 * road-nestjs.
 */
final class IamScopes
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /** @param  array<string,mixed>  $input */
    public function create(array $input): Scope
    {
        $body = $this->http->request('POST', '/iam/authorization/scopes', $input);

        return Scope::from($this->unwrap($body));
    }

    public function get(string $scopeId): Scope
    {
        $body = $this->http->request('GET', '/iam/authorization/scopes/'.rawurlencode($scopeId));

        return Scope::from($this->unwrap($body));
    }

    public function lookup(string $type, string $externalId): Scope
    {
        $body = $this->http->request('GET', '/iam/authorization/scopes/lookup', null, [
            'type' => $type,
            'externalId' => $externalId,
        ]);

        return Scope::from($this->unwrap($body));
    }
}
