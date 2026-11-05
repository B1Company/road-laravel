<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Assignment;
use B1Road\Laravel\DTO\CheckDecision;
use B1Road\Laravel\DTO\CheckExplanation;
use B1Road\Laravel\DTO\CheckResult;
use B1Road\Laravel\Exceptions\RoadApiException;

/**
 * `Road::client()->iam()->*`: the control plane over `/authz/*`. Checks, scopes,
 * roles (via `scope()`) and assignments. The tree is keyed by scope id, exposed
 * through `iam()->scope($scopeId)->roles()` so the scope id is never threaded
 * through a flat method.
 */
final class Iam
{
    use UnwrapsData;

    /** Mirrors the API's cap on `codes` per check (it 400s above this). */
    private const MAX_CHECK_CODES = 100;

    private ?IamScopes $scopes = null;

    private ?IamAssignments $assignments = null;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /**
     * Check codes at one scope: `POST /authz/checks`. Name the scope with
     * `scopeId`, `businessUnitId`, or `platformId` plus `businessUnitId`. More
     * codes than the API takes at once go in several requests.
     *
     * An answer that does not carry a verdict for every code throws. Reading it
     * as a denial would make a failure look like a decision.
     *
     * @param  array<string,mixed>  $input  `codes` plus the scope, and optionally
     *                                      `catalog`, `subjectType`, `subjectId`.
     */
    public function check(array $input): CheckResult
    {
        /** @var list<string> $codes */
        $codes = array_values((array) ($input['codes'] ?? []));

        $results = [];
        foreach (array_chunk($codes, self::MAX_CHECK_CODES) ?: [[]] as $chunk) {
            $body = $this->http->request('POST', '/authz/checks', ['codes' => $chunk] + $input);
            $rows = $this->unwrap($body)['results'] ?? null;
            foreach ($chunk as $i => $code) {
                $row = is_array($rows) ? ($rows[$i] ?? null) : null;
                if (! is_array($row) || ($row['code'] ?? null) !== $code || ! is_bool($row['allowed'] ?? null)) {
                    throw new RoadApiException("POST /authz/checks answered without a verdict for '{$code}'.", 'malformed_response', 502);
                }
                $results[] = new CheckDecision($code, $row['allowed'], (string) ($row['reason'] ?? ''));
            }
        }

        return new CheckResult(results: $results);
    }

    /**
     * Why a code is or is not allowed at a scope: `GET /authz/checks/explain`.
     * Leave the subject out to ask about the caller. Asking about anyone else
     * takes a System Admin.
     */
    public function explain(string $scopeId, string $code, ?string $subjectType = null, ?string $subjectId = null): CheckExplanation
    {
        $query = array_filter(
            ['scopeId' => $scopeId, 'code' => $code, 'subjectType' => $subjectType, 'subjectId' => $subjectId],
            static fn (?string $value): bool => $value !== null,
        );
        $data = $this->unwrap($this->http->request('GET', '/authz/checks/explain', null, $query));

        $held = [];
        foreach ((array) ($data['heldRoles'] ?? []) as $row) {
            if (is_array($row)) {
                $held[] = Assignment::from($row);
            }
        }

        return new CheckExplanation(
            code: (string) ($data['code'] ?? $code),
            allowed: ($data['allowed'] ?? false) === true,
            reason: (string) ($data['reason'] ?? ''),
            chain: array_values(array_filter((array) ($data['chain'] ?? []), 'is_string')),
            heldRoles: $held,
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
