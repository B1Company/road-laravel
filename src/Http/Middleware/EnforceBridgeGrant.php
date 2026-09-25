<?php

declare(strict_types=1);

namespace B1Road\Laravel\Http\Middleware;

use B1Road\Laravel\Bridge\BridgeAccessDenied;
use B1Road\Laravel\Bridge\BridgeContext;
use B1Road\Laravel\Bridge\BridgeContextCache;
use B1Road\Laravel\Client\Resources\Bridge;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\RoadManager;
use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `road.bridge` — Platform Bridge enforcement on the provider side. The
 * Laravel port of `bridgeEnforce()` from `@b1-road/node-core`.
 *
 *   Route::middleware('road.bridge:read:Charge')->get('/charges', …);
 *   Route::middleware('road.bridge:read:Charge,buId')->get('/bu/{buId}/charges', …);
 *   Route::middleware('road.bridge:read:Charge,buId,userId')->get('/bu/{buId}/users/{userId}/charges', …);
 *
 * Args: the permission the route requires (omit to only resolve the context),
 * then where to read the tenant this request touches, then the end-user it is
 * for. A source is a route-parameter name or `input:<key>`. For anything
 * else, register a resolver once with {@see resolveTenantUsing()} /
 * {@see resolveActingUserUsing()}.
 *
 * Per request it:
 *
 *   1. reads the brokered token from `Authorization: Bearer …`;
 *   2. resolves its authorization context from the cache, or from Road with
 *      your platform's service credential (`Road::asService()`);
 *   3. enforces the tenant binding whenever the token names a tenant, and the
 *      acting-user binding whenever it names an end-user. Road cannot make
 *      these checks: only you know whose data the request touches;
 *   4. denies when the required permission is not in the context;
 *   5. attaches the context to the request ({@see BridgeContext::of()});
 *   6. reports the attempt to Road after the response has gone out.
 *
 * **Fail mode.** When Road cannot be reached, the request is refused with 503
 * unless a cached context is younger than `road.platform_bridge.max_staleness`
 * seconds. That defaults to 0: fail-closed. Raising it trades a bounded window
 * of stale answers for surviving a Road outage (the Node SDK defaults to 300).
 * A refusal from Road (any 4xx but 408/429) is never an outage: it denies at
 * once and is never answered from cache.
 */
final class EnforceBridgeGrant
{
    /** Request attribute carrying the attempt to report after the response. */
    private const ATTEMPT = 'roadBridgeAttempt';

    /** Verbs treated as read-only for TTL purposes. Mirrors the Node SDK. */
    private const READ_ACTIONS = ['read', 'list', 'view', 'get'];

    /** @var (Closure(Request): (string|null))|null */
    private static ?Closure $tenantResolver = null;

    /** @var (Closure(Request): (string|null))|null */
    private static ?Closure $actingUserResolver = null;

    private ?Bridge $bridge = null;

    public function __construct(
        private readonly RoadManager $road,
        private readonly BridgeContextCache $cache,
        private readonly ConfigRepository $config,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Return the business-unit id the request touches. Used on routes that do
     * not name a tenant source in the middleware string. Pass null to clear.
     *
     * @param  (Closure(Request): (string|null))|null  $resolver
     */
    public static function resolveTenantUsing(?Closure $resolver): void
    {
        self::$tenantResolver = $resolver;
    }

    /**
     * Return the end-user id the request is for. Used on routes that do not
     * name an acting-user source in the middleware string. Pass null to clear.
     *
     * @param  (Closure(Request): (string|null))|null  $resolver
     */
    public static function resolveActingUserUsing(?Closure $resolver): void
    {
        self::$actingUserResolver = $resolver;
    }

    public function handle(
        Request $request,
        Closure $next,
        string $permission = '',
        string $tenantSource = '',
        string $actingUserSource = '',
    ): Response {
        $permission = $permission !== '' ? $permission : null;

        $token = $request->bearerToken();
        if ($token === null || trim($token) === '') {
            return $this->deny(401, 'missing_token', 'No bearer token was presented.', $permission, null);
        }
        $token = trim($token);

        $key = BridgeContextCache::keyFor($token);
        $now = self::now();
        // Captured before Road is asked, and used for every read and write
        // below: a revocation that lands mid-call must not see its pre-revocation
        // answer stored where the next request reads it.
        $generation = $this->cache->generation();
        $cached = $this->cache->get($key, $generation);
        $maxAge = $this->isRead($permission) ? $this->seconds('read_ttl', 60) : $this->seconds('write_ttl', 5);

        // Strict `<`: a bound of 0 must mean "never reuse", as `write_ttl: 0` documents.
        if ($cached !== null && $now - $cached['fetchedAt'] < $maxAge && self::beforeExpiry($cached['usableUntil'], $now)) {
            $context = $cached['context'];
        } else {
            try {
                if ($permission !== null && $this->cache->isDegraded($key, $generation)) {
                    // Straight to the answerable question. Not cached: this answer is
                    // scoped to one permission and the cache is keyed on the token, so
                    // storing it would let a verdict about `read:X` answer `delete:X`.
                    $context = $this->bridge()->authorize($token, [$permission]);
                } else {
                    $context = $this->bridge()->authorize($token);
                    $this->cache->put($key, $context, $token, self::now(), $generation);
                }
            } catch (LogicException $e) {
                throw $e;
            } catch (Throwable $e) {
                $status = self::statusOf($e);

                if ($status === 422 && $permission !== null) {
                    // Road lost the mint record and will not enumerate the token, but it
                    // still answers "is this consumer allowed X". Ask that, and remember to.
                    $this->cache->markDegraded($key, $token, $now, $generation);
                    try {
                        $context = $this->bridge()->authorize($token, [$permission]);
                    } catch (LogicException $e) {
                        throw $e;
                    } catch (Throwable) {
                        // Never fall back to a stale allow here: that is exactly the
                        // hazard the refusal/outage split exists to prevent.
                        return $this->deny(403, 'not_authorized', 'Road refused to authorize this token.', $permission, $key);
                    }
                } elseif ($status !== null && $status >= 400 && $status < 500 && $status !== 408 && $status !== 429) {
                    // A refusal is a decision, not an outage: retrying cannot change it,
                    // and a warm cached allow must not override it.
                    return $this->deny(403, 'not_authorized', 'Road refused to authorize this token.', $permission, $key);
                } elseif ($cached !== null
                    && $now - $cached['fetchedAt'] < $this->seconds('max_staleness', 0)
                    && self::beforeExpiry($cached['usableUntil'], $now)) {
                    // Bounded last-known-good, and never past the token's own expiry.
                    $context = $cached['context']->withServedStale();
                } else {
                    return $this->deny(
                        503,
                        'authorization_unavailable',
                        'Road could not be reached and no sufficiently fresh cached authorization is available.',
                        $permission,
                        $key,
                    );
                }
            }
        }

        $tenantResolver = $this->resolver($tenantSource, self::$tenantResolver);
        $actingUserResolver = $this->resolver($actingUserSource, self::$actingUserResolver);

        // A degraded answer carries no tenant, so on a tenant-scoped surface it
        // cannot be checked and must not be served.
        if ($context->degraded && $tenantResolver !== null) {
            return $this->deny(
                403,
                'degraded_context',
                'Road could not resolve what this token was minted for and answered from the provider grant instead. '
                .'That answer carries no tenant, so it cannot authorize a tenant-scoped request.',
                $permission,
                $key,
            );
        }

        // Keyed on whether the token NAMES a tenant, not on which leg minted it (B1-618).
        if ($context->businessUnitId !== null || $context->leg === 'extensions') {
            if ($tenantResolver === null) {
                if ($this->config->get('road.platform_bridge.strict_tenancy', true)) {
                    return $this->deny(
                        403,
                        'tenant_unverifiable',
                        'This token is scoped to one business unit, but no tenant resolver is configured, so the binding cannot be enforced.',
                        $permission,
                        $key,
                    );
                }
            } else {
                $requested = $tenantResolver($request);
                if ($requested === null || $requested === '' || $requested !== $context->businessUnitId) {
                    return $this->deny(403, 'cross_tenant', 'This token is not scoped to the business unit this request touches.', $permission, $key);
                }
            }
        }

        if ($context->onBehalfOfUser !== null) {
            if ($actingUserResolver === null) {
                if ($this->config->get('road.platform_bridge.strict_acting_user', true)) {
                    return $this->deny(
                        403,
                        'acting_user_unverifiable',
                        'This token was minted on behalf of a specific end-user, but no acting-user resolver is configured, so the binding cannot be enforced.',
                        $permission,
                        $key,
                    );
                }
            } else {
                $actingUser = $actingUserResolver($request);
                if ($actingUser === null || $actingUser === '' || $actingUser !== $context->onBehalfOfUser) {
                    return $this->deny(
                        403,
                        'cross_user',
                        'This token was minted on behalf of a different end-user than the one this request is for.',
                        $permission,
                        $key,
                    );
                }
            }
        }

        if ($permission !== null && ! $context->can($permission)) {
            $this->queueAttempt($request, $token, $permission, false);

            return $this->deny(403, 'not_authorized', "The presented token is not authorized for '{$permission}'.", $permission, $key);
        }

        if ($permission !== null) {
            $this->queueAttempt($request, $token, $permission, true);
        }

        $request->attributes->set(BridgeContext::ATTRIBUTE, $context);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * Report the attempt once the response has been sent, so the consumer never
     * waits on Road's audit trail. Failures are swallowed: the decision is made.
     */
    public function terminate(Request $request, Response $response): void
    {
        $attempt = $request->attributes->get(self::ATTEMPT);
        if (! is_array($attempt)) {
            return;
        }
        $request->attributes->remove(self::ATTEMPT);

        try {
            $this->bridge()->reportAttempt(...$attempt);
        } catch (Throwable) {
            // Fire-and-forget by design.
        }
    }

    private function queueAttempt(Request $request, string $token, string $permission, bool $allowed): void
    {
        if (! $this->config->get('road.platform_bridge.report_attempts', true)) {
            return;
        }

        // `path()` never carries the query string, and it must not: a partner's
        // query string routinely holds customer ids and e-mail addresses, and
        // whatever reaches Road is kept in its audit log under its retention.
        $request->attributes->set(self::ATTEMPT, [
            'brokeredToken' => $token,
            'permission' => $permission,
            'allowed' => $allowed,
            'method' => $request->getMethod(),
            'path' => '/'.ltrim($request->path(), '/'),
        ]);
    }

    private function deny(int $status, string $reason, string $detail, ?string $permission, ?string $key): JsonResponse
    {
        $this->events->dispatch(new BridgeAccessDenied($reason, $permission, $key));

        return new JsonResponse(['error' => $reason, 'error_description' => $detail], $status);
    }

    private function bridge(): Bridge
    {
        if ($this->bridge !== null) {
            return $this->bridge;
        }

        try {
            return $this->bridge = $this->road->asService()->client()->bridge();
        } catch (RoadAuthnException $e) {
            // A configuration error, not the consumer's fault: surface it as a 500
            // rather than a 401 that would send them chasing their own token.
            throw new LogicException(
                'The road.bridge middleware asks Road with your platform\'s service credential, and none is configured. '
                .'Set ROAD_SERVICE_CLIENT_ID and ROAD_SERVICE_CLIENT_SECRET (or the private_key_jwt config).',
                previous: $e,
            );
        }
    }

    /** @return (Closure(Request): (string|null))|null */
    private function resolver(string $source, ?Closure $fallback): ?Closure
    {
        if ($source === '') {
            return $fallback;
        }

        return static function (Request $request) use ($source): ?string {
            $value = str_starts_with($source, 'input:')
                ? $request->input(substr($source, 6))
                : $request->route($source);

            return is_string($value) && $value !== '' ? $value : null;
        };
    }

    private function isRead(?string $permission): bool
    {
        return $permission === null
            || in_array(strtolower(explode(':', $permission)[0]), self::READ_ACTIONS, true);
    }

    private function seconds(string $option, int $default): int
    {
        return max(0, (int) $this->config->get('road.platform_bridge.'.$option, $default));
    }

    /** The status Road answered with, or null when it never answered (network, bad body). */
    private static function statusOf(Throwable $e): ?int
    {
        if ($e instanceof RoadNetworkException || ! $e instanceof RoadException) {
            return null;
        }

        return $e->httpStatus();
    }

    private static function beforeExpiry(?float $usableUntil, float $now): bool
    {
        return $usableUntil === null || $now < $usableUntil;
    }

    /** Seconds, sub-second precise, on Carbon's clock so tests can travel in time. */
    private static function now(): float
    {
        return Carbon::now()->getTimestampMs() / 1000;
    }
}
