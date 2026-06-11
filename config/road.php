<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    | Logical name of the Road environment this app talks to. Used to pick
    | sane defaults for API URLs and for diagnostics in road:doctor.
    | Allowed: 'production' | 'sandbox' | 'local'.
    */

    'environment' => env('ROAD_ENVIRONMENT', 'sandbox'),

    'api' => [
        'base_url' => env('ROAD_API_BASE_URL'),
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
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Token Store
    |--------------------------------------------------------------------------
    | Where the BFF caches Auth Server tokens between requests. MVP ships
    | the `session` driver only; `cache` and `database` arrive in follow-ups.
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

];
