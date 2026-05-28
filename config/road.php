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

    'inertia' => [
        'enabled' => (bool) env('ROAD_INERTIA_ENABLED', true),
        'share_user' => true,
        'share_business_units' => true,
    ],

    'debug' => [
        'header_enabled' => (bool) env('ROAD_DEBUG_HEADER', ! app()->environment('production')),
        'log_channel' => env('ROAD_LOG_CHANNEL', 'stack'),
    ],

];
