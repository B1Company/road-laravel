<?php

declare(strict_types=1);
use B1Road\Laravel\Environments;

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    | Logical name of the Road environment this app talks to. Used to pick
    | sane defaults for API URLs and for diagnostics in road:doctor.
    | Allowed: 'production' | 'sandbox' | 'local'.
    |
    | Going live is this one variable: 'sandbox' and 'production' are both
    | hosted by Eduzz Plat, so ROAD_API_BASE_URL below defaults from whichever
    | you name. What does NOT carry over is credentials — the two are separate
    | instances, and a client registered in one does not exist in the other.
    |
    | `ROAD_ENV` is accepted as a fallback because that is the name the Node
    | SDKs and the Road MCP use, and it is the name the published guide prints.
    | A partner running Laravel beside a Node service should not have to know
    | that this one package spells it differently.
    */

    'environment' => env('ROAD_ENVIRONMENT', env('ROAD_ENV', 'sandbox')),

    'api' => [
        /*
        | Defaults to the hosted URL for 'environment'. Set it explicitly to
        | point at a local stack, a tunnel or your own gateway — and you must
        | set it when ROAD_ENVIRONMENT=local, which Plat does not host.
        */
        'base_url' => env('ROAD_API_BASE_URL')
            ?: Environments::apiUrl(
                env('ROAD_ENVIRONMENT', env('ROAD_ENV', 'sandbox'))
            ),
        'version' => env('ROAD_API_VERSION', 'alpha'),
        'timeout' => (int) env('ROAD_API_TIMEOUT', 10),
        'jwks_ttl' => (int) env('ROAD_API_JWKS_TTL', 600),

        /*
        |----------------------------------------------------------------------
        | Retries
        |----------------------------------------------------------------------
        | The transport retries only transient failures — 5xx and network
        | errors — with exponential backoff + jitter. A 429 is never retried;
        | its Retry-After is surfaced on RoadRateLimitException instead.
        | Mutations carry an Idempotency-Key so a replay is de-duplicated.
        */

        'retry' => [
            'enabled' => (bool) env('ROAD_API_RETRY_ENABLED', true),
            'max_attempts' => (int) env('ROAD_API_RETRY_MAX_ATTEMPTS', 3),
            'base_delay_ms' => (int) env('ROAD_API_RETRY_BASE_DELAY_MS', 250),
        ],
    ],

    'auth_server' => [
        'issuer_url' => env('AUTH_SERVER_ISSUER_URL'),
        'audience' => env('AUTH_SERVER_AUDIENCE'),
        'client_id' => env('AUTH_SERVER_CLIENT_ID'),
        'client_secret' => env('AUTH_SERVER_CLIENT_SECRET'),
        'redirect_uri' => env('AUTH_SERVER_REDIRECT_URI'),
        'scopes' => ['openid', 'profile', 'email', 'offline_access'],
    ],

    /*
    |--------------------------------------------------------------------------
    | BFF Proxy
    |--------------------------------------------------------------------------
    | Mounts /road-api/{any?} to forward browser calls to Road with the
    | server-stored Bearer attached. Paths outside `allow` return 404.
    */

    'proxy' => [
        'enabled' => (bool) env('ROAD_PROXY_ENABLED', true),
        'prefix' => env('ROAD_PROXY_PREFIX', 'road-api'),
        'allow' => [
            'organization/*',
            'iam/identity/*',
            'iam/authorization/*',
            // The caller's own self-service endpoints (business units, profile,
            // permissions) — what @b1-road/react widgets read in cookie mode.
            'me/*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Store
    |--------------------------------------------------------------------------
    | Where the BFF keeps Auth Server tokens between requests.
    |
    |   session — in the session payload (default; simplest, single-node).
    |   cache   — in the cache store (Redis) keyed by session id, for
    |             horizontally-scaled / Octane BFFs where every worker must read
    |             the same tokens. TTL = the session lifetime.
    |
    | (`database` is not yet shipped — revisit when a consumer needs it.)
    */

    'token_store' => env('ROAD_TOKEN_STORE', 'session'),

    /*
    |--------------------------------------------------------------------------
    | Service-to-service mode
    |--------------------------------------------------------------------------
    | Credentials for `Road::asService()` — calls made from queued jobs, cron,
    | and other contexts with no browser session. The SDK acquires a token from
    | the Auth Server (cached until expiry) via `client_credentials` (a shared
    | secret) or `private_key_jwt` (a signed assertion). Leave `client_id` unset
    | to disable; `Road::asService()` then raises a clear error.
    */

    'service' => [
        'mode' => env('ROAD_SERVICE_MODE'), // null | 'client_credentials' | 'private_key_jwt'
        'client_id' => env('ROAD_SERVICE_CLIENT_ID'),
        'client_secret' => env('ROAD_SERVICE_CLIENT_SECRET'),
        'key_id' => env('ROAD_SERVICE_KEY_ID'),
        'private_key' => env('ROAD_SERVICE_PRIVATE_KEY'), // PEM literal or a path to one
        'algorithm' => env('ROAD_SERVICE_ALGORITHM', 'RS256'),
        'audience' => env('ROAD_SERVICE_AUDIENCE'), // defaults to auth_server.audience
    ],

    'inertia' => [
        'enabled' => (bool) env('ROAD_INERTIA_ENABLED', true),
        'share_user' => true,
        'share_business_units' => true,
    ],

    'debug' => [
        // Default on outside production. Read APP_ENV directly rather than
        // app()->environment() so this config file stays cacheable (and so a
        // bare container — e.g. static analysis — can load it without booting).
        'header_enabled' => (bool) env('ROAD_DEBUG_HEADER', env('APP_ENV', 'production') !== 'production'),
        'log_channel' => env('ROAD_LOG_CHANNEL', 'stack'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    | Opt-in receiver for Road webhook deliveries. When enabled, a single
    | `POST {path}` route is mounted (outside the `web` group — no CSRF). Each
    | verified delivery is dispatched onto Laravel's event bus. Authenticity is
    | an HMAC-SHA256 signature over `"{timestamp}.{rawBody}"`; set the endpoint
    | secret as `ROAD_WEBHOOK_SECRET`. `verify=false` skips verification, and is
    | honored only outside production (local development).
    */

    'webhooks' => [
        'enabled' => (bool) env('ROAD_WEBHOOKS_ENABLED', false),
        'path' => env('ROAD_WEBHOOK_PATH', 'road/webhooks'),
        'secret' => env('ROAD_WEBHOOK_SECRET'),
        'tolerance' => (int) env('ROAD_WEBHOOK_TOLERANCE', 300),
        'verify' => (bool) env('ROAD_WEBHOOK_VERIFY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform Bridge (provider side)
    |--------------------------------------------------------------------------
    | Settings for the `road.bridge` middleware, which checks the brokered
    | tokens other platforms present to your API. It calls Road with the
    | `service` credential above. Not to be confused with `bridges.gate` below.
    |
    | read_ttl / write_ttl — seconds a token's answer is reused for read verbs
    |   (read, list, view, get) and for everything else. 0 asks Road every time.
    |   An answer never outlives the token's own expiry, whatever these say.
    | max_staleness — the fail mode. Seconds a cached answer may still be served
    |   while Road is unreachable. 0 (default) fails closed: no fresh answer,
    |   no access (503). Raise it to ride out a Road outage on answers at most
    |   this old (300 at most is a sane ceiling). A refusal from Road is never
    |   overridden by the cache, and drops that token's cached answer.
    | authorize_timeout — seconds per attempt at Road's authorize endpoint.
    |   The middleware retries once at most, so an outage answers 503 in about
    |   twice this, instead of after the general `api.timeout` and retries.
    | strict_tenancy / strict_acting_user — refuse a token that names a tenant
    |   (or an end-user) on a route that gives no way to check it.
    | cache_store — a store every worker shares (redis, database, file). The
    |   `array` store forgets between requests, which disables the cache.
    */

    'platform_bridge' => [
        'cache_store' => env('ROAD_PLATFORM_BRIDGE_CACHE_STORE'),
        'read_ttl' => (int) env('ROAD_PLATFORM_BRIDGE_READ_TTL', 60),
        'write_ttl' => (int) env('ROAD_PLATFORM_BRIDGE_WRITE_TTL', 5),
        'max_staleness' => (int) env('ROAD_PLATFORM_BRIDGE_MAX_STALENESS', 0),
        'authorize_timeout' => (float) env('ROAD_PLATFORM_BRIDGE_AUTHORIZE_TIMEOUT', 2),
        'strict_tenancy' => true,
        'strict_acting_user' => true,
        'report_attempts' => (bool) env('ROAD_PLATFORM_BRIDGE_REPORT_ATTEMPTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bridges
    |--------------------------------------------------------------------------
    |
    | Opt-in integrations with Laravel's own subsystems. (Platform Bridge, the
    | cross-platform capability, is configured under `platform_bridge` above.)
    |
    | gate — route `road:{action}:{Subject}` abilities through Road's engine so
    | `Gate::allows('road:read:Project', $buId)`, `$user->can(...)`, and Blade
    | `@can(...)` answer via Road. Off by default: a host that never uses the
    | Gate for Road shouldn't pay for the `Gate::before` hook.
    |
    */

    'bridges' => [
        'gate' => (bool) env('ROAD_BRIDGE_GATE', false),
    ],

];
