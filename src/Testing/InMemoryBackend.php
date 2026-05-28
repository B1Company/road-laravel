<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use B1Road\Laravel\Exceptions\RoadNotFoundException;

/**
 * In-memory router for fake Road API calls. Port of
 * `apps/sdks/road-nestjs/src/testing/in-memory-backend.ts`.
 *
 * Matches `method + path` against a small set of handlers and serves
 * data from the test scenario. Mutations (PATCH/POST/DELETE) are not
 * routed in this MVP slice — they land with the full client surface.
 */
final class InMemoryBackend
{
    /** @var list<array{method:string, path:string, body:?array<string,mixed>}> */
    public array $calls = [];

    public function __construct(public readonly RoadScenario $scenario)
    {
    }

    /**
     * @param  array<string,mixed>|null  $body
     * @param  string|null  $userId  Hint of which user is calling (from FakeRoadClient context).
     * @return array{status:int, body:array<string,mixed>}
     */
    public function handle(string $method, string $path, ?array $body = null, ?string $userId = null): array
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body];

        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');

        // /iam/identity/me
        if ($method === 'GET' && $path === '/iam/identity/me') {
            $user = $userId !== null ? $this->scenario->findUser($userId) : null;
            if ($user === null) {
                throw new RoadNotFoundException('No active user in scenario.');
            }

            return ['status' => 200, 'body' => ['data' => $user]];
        }

        // /me/business-units
        if ($method === 'GET' && $path === '/me/business-units') {
            $memberships = $userId !== null ? $this->scenario->memberships($userId)['memberships'] : [];

            return ['status' => 200, 'body' => [
                'data' => [
                    'memberships'        => $memberships,
                    'pendingInvitations' => [],
                ],
            ]];
        }

        // /me/permissions
        if ($method === 'GET' && $path === '/me/permissions') {
            return ['status' => 200, 'body' => ['data' => ['byBusinessUnit' => []]]];
        }

        // /organization/business-units/{buId}
        if ($method === 'GET' && preg_match('#^/organization/business-units/([^/]+)$#', $path, $m) === 1) {
            $bu = $this->scenario->findBusinessUnit($m[1]);
            if ($bu === null) {
                throw new RoadNotFoundException(sprintf('Business unit %s not in scenario.', $m[1]));
            }

            return ['status' => 200, 'body' => ['data' => $bu]];
        }

        throw new RoadNotFoundException(sprintf('No fake handler for %s %s.', $method, $path));
    }
}
