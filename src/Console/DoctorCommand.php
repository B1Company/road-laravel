<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\JwksCache;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Throwable;

/**
 * `php artisan road:doctor` — connectivity + configuration smoke check.
 *
 * Catches the real things integrators get wrong in prod:
 *  - missing env vars
 *  - Road API + Auth Server reachability
 *  - JWKS load
 *  - clock skew vs Auth Server (the silent JWT-validation killer)
 *  - redirect_uri scheme/host visibility (root cause of most OIDC fails)
 *  - session driver suitable for the BFF token store
 *  - middleware aliases + proxy mount
 *
 * Each check prints ✓/✗/⚠ with a clear next step on failure.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'road:doctor';

    /** @var string */
    protected $description = 'Verify Road SDK configuration and connectivity to Auth Server + Road API.';

    public function handle(
        Application $app,
        ConfigRepository $config,
        HttpFactory $http,
        OidcDiscovery $discovery,
        JwksCache $jwks,
        Router $router,
    ): int {
        $ok = true;

        $ok &= $this->checkConfigKey($config, 'road.api.base_url', 'Road API base URL');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.issuer_url', 'Auth Server issuer URL');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.client_id', 'Auth Server client ID');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.client_secret', 'Auth Server client secret');
        $ok &= $this->checkRedirectUri($config);
        $ok &= $this->checkSessionDriver($app, $config);

        $ok &= $this->checkRoadApiReachable($config, $http);
        $ok &= $this->checkAuthServerDiscovery($discovery);
        $ok &= $this->checkClockSkew($config, $http);
        $ok &= $this->checkJwks($jwks);
        $ok &= $this->checkMiddlewareAliases($router);
        $ok &= $this->checkProxyMounted($config, $router);

        $this->newLine();
        if ($ok) {
            $this->info('All checks passed. ✓');

            return self::SUCCESS;
        }

        $this->error('Some checks failed. ✗');

        return self::FAILURE;
    }

    private function checkConfigKey(ConfigRepository $config, string $key, string $label): bool
    {
        $value = $config->get($key);
        if (is_string($value) && $value !== '') {
            $this->line("  ✓ $label set");

            return true;
        }
        $this->line("  ✗ $label is empty — set it in .env");

        return false;
    }

    private function checkRedirectUri(ConfigRepository $config): bool
    {
        $uri = (string) $config->get('road.auth_server.redirect_uri', '');
        if ($uri === '') {
            $this->line('  ✗ Auth Server redirect URI is empty — set AUTH_SERVER_REDIRECT_URI');

            return false;
        }

        $parts = parse_url($uri);
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true)) {
            $this->line("  ✗ redirect_uri scheme '$scheme' is not http(s)");

            return false;
        }
        if (! is_string($host) || $host === '') {
            $this->line('  ✗ redirect_uri is missing a hostname');

            return false;
        }
        if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            $this->line("  ⚠ redirect_uri uses http on a public host ($host) — most Auth Servers require https");
        }

        $this->line("  ✓ redirect_uri shape OK ($scheme://$host…) — verify it's registered in the Auth Server console");

        return true;
    }

    private function checkSessionDriver(Application $app, ConfigRepository $config): bool
    {
        $driver = (string) $config->get('session.driver', 'file');
        if (in_array($driver, ['array', 'null'], true) && ! $app->environment('testing')) {
            $this->line("  ✗ session.driver=$driver — Road BFF needs a persistent driver (file, redis, database, cookie)");

            return false;
        }
        $this->line("  ✓ session.driver=$driver");

        return true;
    }

    private function checkRoadApiReachable(ConfigRepository $config, HttpFactory $http): bool
    {
        $base = (string) $config->get('road.api.base_url', '');
        if ($base === '') {
            return true; // already reported by config check
        }
        try {
            $response = $http->timeout(5)->get(rtrim($base, '/').'/iam/identity/auth/config');
            if ($response->successful() || $response->status() === 401) {
                $this->line('  ✓ Road API reachable ('.$response->status().')');

                return true;
            }
            $this->line('  ✗ Road API returned HTTP '.$response->status());
        } catch (Throwable $e) {
            $this->line('  ✗ Road API unreachable: '.$e->getMessage());
        }

        return false;
    }

    private function checkAuthServerDiscovery(OidcDiscovery $discovery): bool
    {
        try {
            $meta = $discovery->metadata();
            $this->line('  ✓ Auth Server discovery loaded (jwks_uri='.($meta['jwks_uri'] ?? 'missing').')');

            return true;
        } catch (Throwable $e) {
            $this->line('  ✗ Auth Server discovery failed: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Compare local clock against the Auth Server's `Date` HTTP response
     * header. >30s skew breaks JWT exp/nbf validation silently.
     */
    private function checkClockSkew(ConfigRepository $config, HttpFactory $http): bool
    {
        $issuer = (string) $config->get('road.auth_server.issuer_url', '');
        if ($issuer === '') {
            return true;
        }

        try {
            $response = $http->timeout(5)->get(rtrim($issuer, '/').'/.well-known/openid-configuration');
            $dateHeader = $response->header('Date');
            if (! is_string($dateHeader) || $dateHeader === '') {
                $this->line('  ⚠ Auth Server discovery response did not include a Date header — clock-skew check skipped');

                return true;
            }
            $remote = strtotime($dateHeader);
            if ($remote === false) {
                $this->line("  ⚠ Could not parse Auth Server Date header ($dateHeader) — clock-skew check skipped");

                return true;
            }
            $skew = abs(time() - $remote);
            if ($skew > 30) {
                $this->line(sprintf('  ✗ Clock skew vs Auth Server is %ds (>30s) — JWT exp/nbf will reject tokens. Run NTP sync.', $skew));

                return false;
            }
            $this->line(sprintf('  ✓ Clock skew vs Auth Server is %ds (<=30s)', $skew));

            return true;
        } catch (Throwable $e) {
            $this->line('  ⚠ Clock-skew check failed: '.$e->getMessage());

            return true;
        }
    }

    private function checkJwks(JwksCache $jwks): bool
    {
        try {
            $set = $jwks->get();
            $count = count($set->all());
            $this->line("  ✓ JWKS loaded ($count keys)");

            return true;
        } catch (Throwable $e) {
            $this->line('  ✗ JWKS fetch failed: '.$e->getMessage());
        }

        return false;
    }

    private function checkMiddlewareAliases(Router $router): bool
    {
        $aliases = $router->getMiddleware();
        $expected = ['road', 'road.optional', 'road.errors', 'road.inertia'];
        $missing = array_diff($expected, array_keys($aliases));
        if ($missing === []) {
            $this->line('  ✓ Middleware aliases registered (road, road.optional, road.errors, road.inertia)');

            return true;
        }
        $this->line('  ✗ Missing middleware aliases: '.implode(', ', $missing));

        return false;
    }

    private function checkProxyMounted(ConfigRepository $config, Router $router): bool
    {
        if (! $config->get('road.proxy.enabled', true)) {
            $this->line('  ⚠ Proxy is disabled (road.proxy.enabled=false) — skipping mount check');

            return true;
        }

        $prefix = (string) $config->get('road.proxy.prefix', 'road-api');
        foreach ($router->getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            if (str_starts_with($uri, $prefix.'/') || $uri === $prefix) {
                $this->line("  ✓ Proxy mounted at /$prefix");

                return true;
            }
        }
        $this->line("  ✗ Proxy NOT mounted at /$prefix — check road.proxy.enabled and route loading");

        return false;
    }
}
