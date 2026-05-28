<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Testing\FakeRoadClientFactory;
use B1Road\Laravel\Testing\InMemoryBackend;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Contracts\Container\Container;

/**
 * The target of the Road facade. Only methods that are wired today
 * live here — additional surface (authorization primitives, service
 * mode) will be added when each follow-up release lands, not stubbed.
 */
final class RoadManager
{
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

    public function fake(RoadScenario $scenario): RoadFakeAssertions
    {
        $backend = new InMemoryBackend($scenario);

        /** @var FakeRoadClientFactory $factory */
        $factory = $this->container->make(FakeRoadClientFactory::class);

        $this->container->instance(RoadClient::class, $factory->build($backend, $this->context));

        return new RoadFakeAssertions($backend);
    }
}
