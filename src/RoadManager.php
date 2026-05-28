<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Can;
use B1Road\Laravel\Authorization\CanBatch;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Testing\FakeRoadClientFactory;
use B1Road\Laravel\Testing\InMemoryBackend;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Contracts\Container\Container;

/**
 * The target of the Road facade. Only methods that are wired today
 * live here — additional surface will be added when each follow-up
 * release lands, not stubbed.
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

    // -- Authorization -----------------------------------------------------

    /**
     * Build a permission check. Lazy — no HTTP call until `check()` /
     * `trace()` is invoked.
     *
     *   Road::can(Action::Read, Subject::Member)->in($buId)->check();
     */
    public function can(Action $action, Subject $subject): Can
    {
        return new Can(
            $this->container->make(HttpTransportInterface::class),
            $this->context,
            $action,
            $subject,
        );
    }

    /**
     * Build a batched permission check. All `$checks` must share a single
     * scope id, set via `->in($scopeId)` on the returned batch.
     *
     *   $allowed = Road::canMany([
     *       Road::can(Action::Read, Subject::Member),
     *       Road::can(Action::Update, Subject::Role),
     *   ])->in($buId)->resolve();
     *
     * @param  list<Can>  $checks
     */
    public function canMany(array $checks): CanBatch
    {
        return new CanBatch(
            $this->container->make(HttpTransportInterface::class),
            $this->context,
            $checks,
        );
    }

    /**
     * Assert the check passes; throws RoadAuthzException with the
     * DecisionTrace on deny.
     *
     * Implementation: 1 round-trip on allow (the cheap `result()` call),
     * 2 round-trips on deny (the deny verdict + a debug-flag fetch to
     * build the trace). Resilient to backends that don't honor the
     * debug flag — the exception still throws with a null trace.
     */
    public function assert(Can $check): void
    {
        $result = $check->result();
        if ($result->allowed) {
            return;
        }

        $trace = null;
        try {
            $trace = $check->trace();
        } catch (\Throwable) {
            // Best effort — the network-level error has already shown up
            // through `result()`. Throw the deny without a trace.
        }

        throw new RoadAuthzException(
            message: 'Permission denied: '.($result->reason !== '' ? $result->reason : 'no grant matches the required permission.'),
            errorCode: 'permission_denied',
            trace: $trace,
        );
    }

    // -- Test harness ------------------------------------------------------

    public function fake(RoadScenario $scenario): RoadFakeAssertions
    {
        $backend = new InMemoryBackend($scenario);

        /** @var FakeRoadClientFactory $factory */
        $factory = $this->container->make(FakeRoadClientFactory::class);

        $fakeTransport = new \B1Road\Laravel\Testing\FakeHttpTransport($backend, $this->context);
        $this->container->instance(RoadClient::class, $factory->build($backend, $this->context));
        $this->container->instance(HttpTransportInterface::class, $fakeTransport);

        return new RoadFakeAssertions($backend);
    }
}
