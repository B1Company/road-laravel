<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\JwksCache;
use B1Road\Laravel\Inertia\ShareRoadContext;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Inertia\Inertia;
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
        CacheRepository $cache,
    ): int {
        $ok = true;

        $ok &= $this->checkConfigKey($config, 'road.api.base_url', 'Road API base URL');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.issuer_url', 'Auth Server issuer URL');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.client_id', 'Auth Server client ID');
        $ok &= $this->checkConfigKey($config, 'road.auth_server.client_secret', 'Auth Server client secret');
        $ok &= $this->checkRedirectUri($config);
        $ok &= $this->checkSessionDriver($app, $config);

        $ok &= $this->checkRoadApiReachable($config, $http);

        // Preflight the cache store BEFORE the discovery/JWKS checks: both cache
        // their fetch through Laravel's cache repository, so a broken cache backend
        // (e.g. the `database` store with no migrated `cache` table) would throw a
        // storage error that masquerades as an Auth-Server connectivity failure.
        // If the cache is down, we report *that* and skip the two cached checks
        // rather than blame the network — the (uncached) clock-skew check below
        // still proves the Auth Server is reachable.
        $cacheOk = $this->checkCacheStore($cache);
        $ok &= $cacheOk;
        $ok &= $this->checkAuthServerDiscovery($discovery, $cacheOk);
        $ok &= $this->checkClockSkew($config, $http);
        $ok &= $this->checkJwks($jwks, $cacheOk);
        $ok &= $this->checkMiddlewareAliases($router);
        $ok &= $this->checkProxyMounted($config, $router);
        $ok &= $this->checkInertiaSharedProps($config, $router);

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

        $this->line("  ✓ redirect_uri shape OK ({$scheme}://{$host}…) — verify it's registered in the Auth Server console");

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
        // Probe the versioned API surface — routes live under `/api/{version}`,
        // so a bare-host probe would 404 even on a healthy API.
        $version = trim((string) $config->get('road.api.version', 'alpha'), '/');
        try {
            $response = $http->timeout(5)->get(rtrim($base, '/').'/api/'.$version.'/iam/identity/auth/config');
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

    /**
     * Round-trip a sentinel through the configured cache store. Discovery + JWKS
     * cache through this repository, so a broken store surfaces there as a
     * confusing storage error in place of the real network result; probing it
     * first lets those checks report "skipped — cache unavailable" instead.
     */
    private function checkCacheStore(CacheRepository $cache): bool
    {
        $key = 'road:doctor:cache-probe';
        try {
            $cache->put($key, '1', 5);
            $value = $cache->get($key);
            $cache->forget($key);
            if ($value !== '1') {
                $this->line('  ✗ Cache store did not return the value it just stored — check your CACHE_STORE backend.');

                return false;
            }
            $this->line('  ✓ Cache store read/write OK ('.((string) $this->cacheStoreName()).')');

            return true;
        } catch (Throwable $e) {
            $this->line('  ✗ Cache store unavailable: '.$e->getMessage());
            $this->line('     Discovery + JWKS are cached through this store, so their checks are skipped below.');
            $this->line('     Fix the CACHE_STORE backend (e.g. `database` needs a migrated `cache` table; sqlite needs the file).');

            return false;
        }
    }

    private function cacheStoreName(): string
    {
        $store = config('cache.default');

        return is_string($store) ? $store : 'default';
    }

    private function checkAuthServerDiscovery(OidcDiscovery $discovery, bool $cacheOk): bool
    {
        if (! $cacheOk) {
            $this->line('  ⊘ Auth Server discovery — skipped (cache store unavailable; see above)');

            // Not a failure of its own: the cache check already failed and owns
            // the ✗. The clock-skew check below still proves reachability.
            return true;
        }

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

    private function checkJwks(JwksCache $jwks, bool $cacheOk): bool
    {
        if (! $cacheOk) {
            $this->line('  ⊘ JWKS — skipped (cache store unavailable; see above)');

            return true;
        }

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
        $expected = [
            'road',
            'road.optional',
            'road.errors',
            'road.inertia',
            'road.permission',
            'road.permission.attribute',
        ];
        $missing = array_diff($expected, array_keys($aliases));
        if ($missing === []) {
            $this->line('  ✓ Middleware aliases registered ('.implode(', ', $expected).')');

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
        // ->getRoutes() on the collection returns a plain array<Route>,
        // which is cleanly iterable (the RouteCollectionInterface itself
        // isn't typed as iterable for static analysis).
        foreach ($router->getRoutes()->getRoutes() as $route) {
            $uri = ltrim($route->uri(), '/');
            if (str_starts_with($uri, $prefix.'/') || $uri === $prefix) {
                $this->line("  ✓ Proxy mounted at /$prefix");

                return true;
            }
        }
        $this->line("  ✗ Proxy NOT mounted at /$prefix — check road.proxy.enabled and route loading");

        return false;
    }

    /**
     * Inertia shared-props readiness. The auto-mount in
     * RoadServiceProvider::boot() appends ShareRoadContext to the `web`
     * middleware group when Inertia is installed; this check surfaces
     * any path where that didn't happen — disabled by config, custom
     * HTTP kernel that doesn't implement appendMiddlewareToGroup, or a
     * consumer that explicitly removed the middleware after auto-mount.
     *
     * Without this middleware, every <RoadInertiaProvider> in the React
     * tree reads `usePage().props.road` as `undefined` and the entire
     * BFF integration goes sideways with a confusing JS error rather
     * than a server-side diagnostic. Loud-at-boot is exactly the case
     * this command exists for.
     */
    private function checkInertiaSharedProps(ConfigRepository $config, Router $router): bool
    {
        if (! class_exists(Inertia::class)) {
            $this->line('  ⊘ Inertia not installed — skipping shared-props check');

            return true;
        }

        if (! (bool) $config->get('road.inertia.enabled', true)) {
            $this->line('  ⚠ Inertia auto-mount disabled (road.inertia.enabled=false) — `props.road` will not be shared.');
            $this->line('     Set ROAD_INERTIA_ENABLED=true or add ShareRoadContext to your Inertia middleware group manually.');

            return true;
        }

        $webGroup = $router->getMiddlewareGroups()['web'] ?? [];
        if (in_array(ShareRoadContext::class, $webGroup, true)) {
            $this->line('  ✓ Inertia shared props wired (props.road will be available in every Inertia render)');

            return true;
        }

        $this->line('  ✗ ShareRoadContext middleware is NOT in the `web` middleware group.');
        $this->line('     props.road will be undefined in your React tree and <RoadInertiaProvider> will fail.');
        $this->line('     Likely causes:');
        $this->line('       • A custom HTTP kernel that does not implement appendMiddlewareToGroup');
        $this->line('       • Bootstrap code that explicitly removed the middleware after auto-mount');
        $this->line('     Fix: add `\\B1Road\\Laravel\\Inertia\\ShareRoadContext::class` to your `web` middleware group.');

        return false;
    }
}
