<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

use PHPUnit\Framework\Assert;

/**
 * Opaque assertion handle returned by `Road::fake()`. Wraps the
 * in-memory backend so tests don't reach into its internals — the only
 * supported surface is `assertCalled`, `assertNothingCalled`, and
 * `assertCallCount`.
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
}
