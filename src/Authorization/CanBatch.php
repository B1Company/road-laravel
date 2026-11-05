<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\Resources\Iam;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;

/**
 * Batched permission checks for one business unit. Built by
 * `Road::canMany([...])`. One `POST /authz/checks` answers every code that
 * shares a catalog, so a batch of Road codes is one round-trip, and mixing them
 * with your platform's codes is two.
 *
 *   $allowed = Road::canMany([
 *       Road::can(Action::Read,   Subject::Member),
 *       Road::can(Action::Update, Subject::Role),
 *   ])->in($buId)->resolve();
 *   // → [true, false]
 */
final class CanBatch
{
    private ?string $businessUnitId = null;

    private ?string $scopeId = null;

    /** @param  list<Can>  $checks */
    public function __construct(
        private readonly HttpTransportInterface $http,
        private readonly RoadContext $context,
        private readonly array $checks,
    ) {}

    public function in(string $businessUnitId): self
    {
        $this->businessUnitId = $businessUnitId;
        $this->scopeId = null;

        return $this;
    }

    /** Check at a scope you already hold the id of. See {@see Can::inScope()}. */
    public function inScope(string $scopeId): self
    {
        $this->scopeId = $scopeId;
        $this->businessUnitId = null;

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
        if (($this->businessUnitId ?? $this->scopeId ?? '') === '') {
            throw new \LogicException(
                'Road::canMany(...) requires ->in($buId) before resolve().'
            );
        }

        /** @var array<string, array{address:array<string,string>, positions:list<int>, codes:list<string>}> $groups */
        $groups = [];
        foreach ($this->checks as $position => $check) {
            $bound = clone $check;
            $address = ($this->scopeId !== null ? $bound->inScope($this->scopeId) : $bound->in((string) $this->businessUnitId))->address();
            $key = (string) json_encode($address);
            $groups[$key] ??= ['address' => $address, 'positions' => [], 'codes' => []];
            $groups[$key]['positions'][] = $position;
            $groups[$key]['codes'][] = $check->permissionString();
        }

        $iam = new Iam($this->http);
        $allowed = [];
        foreach ($groups as $group) {
            $results = $iam->check($group['address'] + ['codes' => $group['codes']])->results;
            foreach ($group['positions'] as $i => $position) {
                $allowed[$position] = $results[$i]->allowed;
            }
        }
        ksort($allowed);

        return array_values($allowed);
    }
}
