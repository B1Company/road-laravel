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
        // Runs in EVERY app environment, unlike the guards below.
        //
        // The others are production-safety checks: a memory session driver or a
        // missing secret is fine while you develop. Mixing Eduzz Plat
        // environments is never fine — `APP_ENV=staging` with
        // `ROAD_ENVIRONMENT=production` is exactly the deploy that most needs
        // telling, and gating this on APP_ENV would skip it there. Mirrors
        // @b1-road/node-core, whose equivalent is also unconditional.
        self::assertOneRoadEnvironment($config);

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

    /**
     * Refuse to boot when the Road API and the Auth Server belong to different
     * Eduzz Plat instances.
     *
     * Mirrors `@b1-road/node-core`'s `reconcileEnvironment`, minus the
     * posture/target reconciliation it does not need: Laravel keeps `APP_ENV`
     * and `ROAD_ENVIRONMENT` as separate variables, so nothing here infers the
     * target from the posture and there is no wrong inference to correct.
     *
     * Surface-aware: the issuer is matched against the Auth Server URL
     * specifically, so pasting the API base into `AUTH_SERVER_ISSUER_URL` is
     * caught here rather than at OIDC discovery with a 404.
     *
     * Silent whenever it cannot judge — a local stack, a tunnel or an Auth
     * Server behind a gateway matches no known origin, and a guard that guesses
     * about setups it does not recognise is a guard people disable.
     */
    private static function assertOneRoadEnvironment(ConfigRepository $config): void
    {
        $declared = (string) $config->get('road.environment', '');
        $baseUrl = (string) $config->get('road.api.base_url', '');
        $issuerUrl = (string) $config->get('road.auth_server.issuer_url', '');

        // No API URL at all. `Environments::apiUrl()` returns null for an
        // environment Plat does not host — `local` — so config/road.php resolves
        // this to an empty string and the app used to boot and fail on its first
        // request. The scheme check below skips an empty value, so nothing else
        // catches it. (CodeRabbit, #613.)
        if ($baseUrl === '') {
            $known = implode("', '", array_keys(Environments::HOSTED));
            throw new RuntimeException(
                "Road SDK configuration is unsafe:\n  - no Road API base URL. ROAD_ENVIRONMENT is "
                ."'{$declared}', which Eduzz Plat does not host — only '{$known}' resolve on their own. "
                .'Set ROAD_API_BASE_URL to the stack you are pointing at.'
            );
        }

        // A Road URL in the wrong slot, both directions. An API base in the
        // issuer slot dies at OIDC discovery with a 404; an Auth Server URL in
        // the API slot makes every proxied request 404 against a host with no
        // `/api/alpha`.
        foreach ([
            'AUTH_SERVER_ISSUER_URL' => [$issuerUrl, 'auth_server', 'Auth Server'],
            'ROAD_API_BASE_URL' => [$baseUrl, 'api', 'API'],
        ] as $env => [$url, $want, $label]) {
            $found = $url !== '' ? Environments::surfaceOf($url) : null;
            if ($found !== null && $found['surface'] !== $want) {
                throw new RuntimeException(
                    "Road SDK configuration is unsafe:\n  - {$env} ('{$url}') is "
                    ."Eduzz Plat's {$found['surface']} URL for {$found['environment']}, not its "
                    ."{$label}. For {$found['environment']} that is "
                    .Environments::HOSTED[$found['environment']][$want]
                );
            }
        }

        $problems = [];
        foreach ([
            'ROAD_API_BASE_URL' => [$baseUrl, 'api'],
            'AUTH_SERVER_ISSUER_URL' => [$issuerUrl, 'auth_server'],
        ] as $env => [$url, $surface]) {
            if ($url === '') {
                continue;
            }
            $belongs = Environments::of($url, $surface);
            if ($belongs !== null && $belongs !== $declared) {
                $problems[] = "ROAD_ENVIRONMENT is '{$declared}' but {$env} ('{$url}') is {$belongs}'s — "
                    .'sandbox and production are separate instances with separate credentials, so this cannot sign anyone in.';
            }
        }

        if ($problems !== []) {
            throw new RuntimeException(
                "Road SDK configuration is unsafe:\n  - "
                .implode("\n  - ", $problems)
                ."\nRun `php artisan road:doctor` for the full diagnostic."
            );
        }
    }
}
