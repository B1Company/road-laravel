<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use PHPUnit\Framework\Assert;

/**
 * Opaque assertion handle returned by `Road::fake()`. Wraps the
 * in-memory backend so tests don't reach into its internals — the only
 * supported surface is `assertCalled`, `assertNothingCalled`,
 * `assertCallCount`, and `invitationToken` (the test's way to read an
 * invitation link token).
 *
 * Mirrors the `Http::assertSent` / `Bus::assertDispatched` style from
 * Laravel's own fakes.
 */
final class RoadFakeAssertions
{
    public function __construct(private readonly InMemoryBackend $backend) {}

    public function assertCalled(string $method, string $path): void
    {
        $method = strtoupper($method);
        $candidatePath = '/'.ltrim($path, '/');

        $found = false;
        foreach ($this->backend->calls as $call) {
            if (
                strtoupper($call['method']) === $method
                && '/'.ltrim($call['path'], '/') === $candidatePath
            ) {
                $found = true;
                break;
            }
        }

        Assert::assertTrue($found, sprintf(
            'Expected fake Road client to be called with %s %s, but it was not. Recorded: %s',
            $method,
            $candidatePath,
            json_encode($this->backend->calls) ?: '[]',
        ));
    }

    public function assertNothingCalled(): void
    {
        Assert::assertSame([], $this->backend->calls, sprintf(
            'Expected no fake Road calls, but %d were recorded: %s',
            count($this->backend->calls),
            json_encode($this->backend->calls) ?: '[]',
        ));
    }

    public function assertCallCount(int $expected): void
    {
        Assert::assertCount($expected, $this->backend->calls);
    }

    /**
     * The token the invitation's latest email link carries: what
     * `Road::client()->invitations()->accept($id, $token)` needs. No fake
     * response returns it, as the API never does. After a resend only the new
     * token is accepted.
     *
     * @throws \InvalidArgumentException when the fake knows no invitation by that id.
     */
    public function invitationToken(string $invitationId): string
    {
        return $this->backend->invitationToken($invitationId);
    }
}
