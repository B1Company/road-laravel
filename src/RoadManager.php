<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Testing\FakeHttpTransport;
use B1Road\Laravel\Testing\FakeRoadClient;
use B1Road\Laravel\Testing\InMemoryBackend;
use B1Road\Laravel\Testing\RoadScenario;
use BadMethodCallException;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert;

/**
 * The target of the Road facade. Exposes the full integrator-facing surface
 * from day one; methods reserved for follow-up releases throw
 * BadMethodCallException so the public shape never moves between milestones.
 */
final class RoadManager
{
    private ?InMemoryBackend $backend = null;

    public function __construct(
        private readonly Container $container,
        private readonly RoadContext $context,
    ) {
    }

    // -- Identity ----------------------------------------------------------

    public function user(): ?RoadUser
    {
        return $this->context->user();
    }

    public function userId(): ?string
    {
        return $this->context->user()?->id;
    }

    public function token(): ?string
    {
        return $this->context->token();
    }

    public function isAuthenticated(): bool
    {
        return $this->context->isAuthenticated();
    }

    public function context(): RoadContext
    {
        return $this->context;
    }

    public function requestId(): string
    {
        return $this->context->requestId();
    }

    // -- Client ------------------------------------------------------------

    public function client(): RoadClient
    {
        return $this->container->make(RoadClient::class);
    }

    // -- Test harness ------------------------------------------------------

    public function fake(RoadScenario $scenario): InMemoryBackend
    {
        $backend = new InMemoryBackend($scenario);
        $this->backend = $backend;

        $fakeClient = new FakeRoadClient(
            new FakeHttpTransport($backend, $this->context),
            $backend,
        );

        $this->container->instance(RoadClient::class, $fakeClient);

        return $backend;
    }

    public function assertCalled(string $method, string $path): void
    {
        if ($this->backend === null) {
            Assert::fail('Road::assertCalled() requires Road::fake() to have been called first.');
        }

        $method = strtoupper($method);
        $candidatePath = '/'.ltrim($path, '/');

        foreach ($this->backend->calls as $call) {
            if (strtoupper($call['method']) === $method && '/'.ltrim($call['path'], '/') === $candidatePath) {
                Assert::assertTrue(true);

                return;
            }
        }

        Assert::fail(sprintf(
            'Expected fake Road client to be called with %s %s, but it was not. Recorded: %s',
            $method,
            $candidatePath,
            json_encode($this->backend->calls) ?: '[]',
        ));
    }

    // -- Reserved surface (follow-up releases) -----------------------------

    public function can(): never
    {
        throw new BadMethodCallException(
            'Road::can() is reserved for a follow-up release (authorization primitives).'
        );
    }

    public function canMany(): never
    {
        throw new BadMethodCallException(
            'Road::canMany() is reserved for a follow-up release (authorization primitives).'
        );
    }

    public function assert(): never
    {
        throw new BadMethodCallException(
            'Road::assert() is reserved for a follow-up release (authorization primitives).'
        );
    }

    public function cannot(): never
    {
        throw new BadMethodCallException(
            'Road::cannot() is reserved for a follow-up release (authorization primitives).'
        );
    }

    public function asService(): never
    {
        throw new BadMethodCallException(
            'Road::asService() is reserved for a follow-up release (service mode).'
        );
    }
}
