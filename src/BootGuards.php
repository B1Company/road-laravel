<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;

/**
 * Fail-loud production-safety checks, run once at provider boot. These are the
 * seatbelt: a misconfiguration that would silently 401/500 every request in
 * production instead surfaces as a clear boot-time error naming the fix.
 *
 * They run **only in production** — local/dev/test boot unimpeded (the memory
 * of the field report is that an over-eager guard tripping on the first dev boot
 * is worse than the risk it guards). `road:doctor` is the interactive twin that
 * checks the full surface (connectivity, clock skew, JWKS) at CLI time; these
 * guards are the subset that must never reach a running production process.
 * Mirrors @b1-road/nestjs's `normalize()` boot throws.
 */
final class BootGuards
{
    public static function assert(Application $app, ConfigRepository $config): void
    {
        if (! $app->environment('production')) {
            return;
        }

        $problems = [];

        // A scheme-less base URL means every outbound Road call resolves wrong
        // (Guzzle can't build the request) — a silent 500 on the first API call.
        $baseUrl = (string) $config->get('road.api.base_url', '');
        if ($baseUrl !== '' && ! preg_match('#^https?://#', $baseUrl)) {
            $problems[] = "ROAD_API_BASE_URL ('{$baseUrl}') has no http(s):// scheme.";
        }

        // OIDC login can't work without client credentials; the auth routes are
        // always mounted, so missing creds means a broken login in production.
        foreach ([
            'road.auth_server.issuer_url' => 'AUTH_SERVER_ISSUER_URL',
            'road.auth_server.client_id' => 'AUTH_SERVER_CLIENT_ID',
            'road.auth_server.client_secret' => 'AUTH_SERVER_CLIENT_SECRET',
        ] as $key => $env) {
            if ((string) $config->get($key, '') === '') {
                $problems[] = "{$env} is not set — OIDC login cannot work.";
            }
        }

        // The BFF token store is session-backed; a non-persistent session driver
        // drops every user's tokens between requests, so nobody stays logged in.
        $sessionDriver = (string) $config->get('session.driver', 'file');
        if (in_array($sessionDriver, ['array', 'null'], true)) {
            $problems[] = "session.driver is '{$sessionDriver}' — the BFF token store needs a persistent driver (file, redis, database, cookie).";
        }

        // Webhooks enabled but no signing secret → the receiver fails closed on
        // every real delivery (503). Better to know at boot than at first webhook.
        if ((bool) $config->get('road.webhooks.enabled', false)
            && (string) $config->get('road.webhooks.secret', '') === '') {
            $problems[] = 'road.webhooks.enabled is true but ROAD_WEBHOOK_SECRET is empty — deliveries will be rejected.';
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Road SDK production configuration is unsafe:\n  - "
                .implode("\n  - ", $problems)
                ."\nRun `php artisan road:doctor` for the full diagnostic."
            );
        }
    }
}
