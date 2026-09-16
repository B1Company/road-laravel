<?php

declare(strict_types=1);

namespace B1Road\Laravel;

/**
 * Eduzz Plat's hosted environments.
 *
 * A mirror of `ROAD_ENVIRONMENTS` in `@b1-road/types`, which is the source of
 * truth. PHP cannot import a TypeScript constant, so this is the one place in
 * the SDK family where a hosted URL is written twice — and it is kept honest by
 * mechanism rather than by memory: `scripts/environment-conformance.mjs` in the
 * monorepo parses both and fails CI when they disagree.
 *
 * If you change a URL here, change it there first.
 */
final class Environments
{
    /**
     * @var array<string, array{api: string, auth_server: string, dev_portal: string}>
     */
    public const HOSTED = [
        'sandbox' => [
            'api' => 'https://api.road-sandbox.b1.app',
            'auth_server' => 'https://auth.road-sandbox.b1.app',
            'dev_portal' => 'https://dev-portal.road-sandbox.b1.app',
        ],
        'production' => [
            'api' => 'https://api.plat.eduzz.com',
            'auth_server' => 'https://auth.plat.eduzz.com',
            'dev_portal' => 'https://portal.plat.eduzz.com',
        ],
    ];

    /**
     * The hosted API base URL for an environment, or `null` for one Plat does
     * not host.
     *
     * `local` is a legitimate value of `ROAD_ENVIRONMENT` and is deliberately
     * absent from the table: a local stack's URL is whatever the developer's
     * docker-compose says, so there is nothing to default to and
     * `ROAD_API_BASE_URL` stays required there.
     */
    public static function apiUrl(?string $environment): ?string
    {
        return self::HOSTED[$environment]['api'] ?? null;
    }

    /**
     * Which hosted surface a URL is, or `null` when it is none of them.
     *
     * Returns the SURFACE as well as the environment, because "a Road URL from
     * the right environment" is not "the right Road URL". Pasting the API base
     * into `AUTH_SERVER_ISSUER_URL` is a common typo, and an environment-only
     * check calls it fine and lets it die at OIDC discovery with a 404.
     *
     * Compared on ORIGIN, not by substring: `https://api.plat.eduzz.com.evil.tld`
     * contains a production hostname and is somebody else's host. A URL that
     * matches nothing — localhost, a tunnel, a preview deploy — is `null` rather
     * than a guess, so callers can stay quiet about setups they cannot judge.
     *
     * @return array{environment: string, surface: string}|null
     */
    public static function surfaceOf(string $url): ?array
    {
        $origin = self::origin($url);
        if ($origin === null) {
            return null;
        }

        foreach (self::HOSTED as $name => $surfaces) {
            foreach ($surfaces as $surface => $surfaceUrl) {
                if (self::origin($surfaceUrl) === $origin) {
                    return ['environment' => $name, 'surface' => $surface];
                }
            }
        }

        return null;
    }

    /**
     * Which hosted environment a URL belongs to, or `null` when it belongs to
     * none of them.
     *
     * Pass `$surface` to also require that it is the right KIND of URL — an
     * `auth_server`, not just any Road host.
     */
    public static function of(string $url, ?string $surface = null): ?string
    {
        $match = self::surfaceOf($url);
        if ($match === null) {
            return null;
        }
        if ($surface !== null && $match['surface'] !== $surface) {
            return null;
        }

        return $match['environment'];
    }

    /**
     * `scheme://host[:port]`, lowercased, with the scheme's default port
     * dropped — what a URL parser would call the origin.
     */
    private static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $origin = $scheme.'://'.strtolower($parts['host']);
        $default = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && $parts['port'] !== $default) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }
}
