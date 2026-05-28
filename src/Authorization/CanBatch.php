<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;

/**
 * Batched permission checks against a single scope. Built by
 * `Road::canMany([...])` — accepts a list of `Can` instances that
 * share the same scope id and resolves them in a single
 * `/iam/authorization/authorize/batch` round-trip.
 *
 *   $allowed = Road::canMany([
 *       Road::can(Action::Read,   Subject::Member),
 *       Road::can(Action::Update, Subject::Role),
 *   ])->in($buId)->resolve();
 *   // → [true, false]
 */
final class CanBatch
{
    private ?string $scopeId = null;

    /** @param  list<Can>  $checks */
    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly RoadContext $context,
        private readonly array $checks,
    ) {
    }

    public function in(string $scopeId): self
    {
        $this->scopeId = $scopeId;

        return $this;
    }

    /**
     * Returns booleans in the same order as the checks passed in.
     *
     * @return list<bool>
     */
    public function resolve(): array
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
                'Road::canMany(...) requires ->in($scopeId) before resolve().'
            );
        }
        if ($this->checks === []) {
            return [];
        }

        $permissions = array_map(fn (Can $c) => $c->permissionString(), $this->checks);

        $body = $this->http->request('POST', '/iam/authorization/authorize/batch', [
            'subjectType' => 'user',
            'subjectId'   => $user->id,
            'scopeId'     => $this->scopeId,
            'permissions' => array_values($permissions),
        ]);

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        /** @var list<array<string,mixed>> $results */
        $results = is_array($data['results'] ?? null) ? $data['results'] : [];

        // Index by permission so we return booleans in the input order.
        $byPermission = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $perm = isset($row['permission']) ? (string) $row['permission'] : null;
            if ($perm === null) {
                continue;
            }
            $byPermission[$perm] = (bool) ($row['allowed'] ?? false);
        }

        return array_values(array_map(
            fn (string $p) => $byPermission[$p] ?? false,
            $permissions,
        ));
    }
}
