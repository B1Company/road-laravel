<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Auth\Service\ServiceCredentials;
use B1Road\Laravel\Auth\Service\ServiceTokenStore;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Can;
use B1Road\Laravel\Authorization\CanBatch;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Testing\FakeHttpTransport;
use B1Road\Laravel\Testing\FakeRoadClientFactory;
use B1Road\Laravel\Testing\InMemoryBackend;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory as HttpFactory;

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
        private readonly ?RoadClient $clientOverride = null,
        private readonly ?HttpTransportInterface $transportOverride = null,
    ) {}

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
        return $this->clientOverride ?? $this->container->make(RoadClient::class);
    }

    /**
     * Return a manager whose client + authorization checks authenticate as the
     * service principal (`client_credentials` / `private_key_jwt`) instead of
     * the request user — for queued jobs, scheduled commands, and other work
     * with no browser session.
     *
     *   Road::asService()->client()->businessUnits($id)->fetch();
     *
     * Builds a dedicated service-mode context so the per-request user context
     * is never mutated; throws if no service credentials are configured.
     */
    public function asService(): self
    {
        /** @var ConfigRepository $config */
        $config = $this->container->make(ConfigRepository::class);

        $credentials = ServiceCredentials::fromConfig($config);
        if ($credentials === null) {
            throw new RoadAuthnException(
                message: 'Road::asService() requires service credentials. Set ROAD_SERVICE_CLIENT_ID + ROAD_SERVICE_CLIENT_SECRET (or the private_key_jwt config).',
                errorCode: 'service_credentials_missing',
            );
        }

        $serviceContext = new RoadContext;
        $serviceContext->setServiceMode(true);
        $serviceContext->setRequestId($this->context->requestId());

        $store = new ServiceTokenStore(
            $this->container->make(OidcDiscovery::class),
            $this->container->make(HttpFactory::class),
            $config,
            $this->container->make(CacheRepository::class),
            $credentials,
        );

        $transport = new HttpTransport(
            $this->container->make(HttpFactory::class),
            $serviceContext,
            $config,
            $this->container->make(RoadTelemetry::class),
            $store,
        );

        return new self($this->container, $serviceContext, new RoadClient($transport), $transport);
    }

    /**
     * Return a manager whose client authenticates as the user identified by a
     * token you already hold — for the rare case where you have an Auth Server
     * access token outside the request session (a background task acting on a
     * specific user's behalf, a test harness). Mirrors `road.as.user(token)` in
     * `@b1-road/nestjs`.
     *
     *   Road::asUser($accessToken)->client()->me()->get();
     */
    public function asUser(string $token): self
    {
        /** @var ConfigRepository $config */
        $config = $this->container->make(ConfigRepository::class);

        $userContext = new RoadContext;
        $userContext->setToken($token);
        $userContext->setRequestId($this->context->requestId());

        $transport = new HttpTransport(
            $this->container->make(HttpFactory::class),
            $userContext,
            $config,
            $this->container->make(RoadTelemetry::class),
        );

        return new self($this->container, $userContext, new RoadClient($transport), $transport);
    }

    // -- Authorization -----------------------------------------------------

    /**
     * Build a permission check. Lazy — no HTTP call until `check()` /
     * `trace()` is invoked.
     *
     *   Road::can(Action::Read, Subject::Member)->in($buId)->check();
     *
     * `$action` and `$subject` accept either the canonical enum or a raw
     * string, so platform-defined subjects outside Road's core algebra work
     * the same way they do in `@b1-road/nestjs`:
     *
     *   Road::can(Action::Create, 'Project')->in($buId)->check();
     */
    public function can(Action|string $action, Subject|string $subject): Can
    {
        return new Can(
            $this->transport(),
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
            $this->transport(),
            $this->context,
            $checks,
        );
    }

    private function transport(): HttpTransportInterface
    {
        return $this->transportOverride ?? $this->container->make(HttpTransportInterface::class);
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

        $fakeTransport = new FakeHttpTransport($backend, $this->context);
        $this->container->instance(RoadClient::class, $factory->build($backend, $this->context));
        $this->container->instance(HttpTransportInterface::class, $fakeTransport);

        return new RoadFakeAssertions($backend);
    }
}
