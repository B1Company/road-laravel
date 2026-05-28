<?php

declare(strict_types=1);

namespace B1Road\Laravel\Console;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\JwksCache;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Throwable;

/**
 * `php artisan road:doctor` — connectivity + configuration smoke check.
 *
 * Useful as a first-line debugging tool when an integrator can't log in.
 * Each check prints its result with a clear ✓/✗ and (on failure) a hint.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'road:doctor';

    /** @var string */
    protected $description = 'Verify Road SDK configuration and connectivity to Auth Server + Road API.';

    public function handle(
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
        $ok &= $this->checkConfigKey($config, 'road.auth_server.redirect_uri', 'Auth Server redirect URI');

        $ok &= $this->checkRoadApiReachable($config, $http);
        $ok &= $this->checkAuthServerDiscovery($discovery);
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
            if (str_starts_with(ltrim($route->uri(), '/'), $prefix.'/') || ltrim($route->uri(), '/') === $prefix) {
                $this->line("  ✓ Proxy mounted at /$prefix");

                return true;
            }
        }
        $this->line("  ✗ Proxy NOT mounted at /$prefix — check road.proxy.enabled and route loading");

        return false;
    }
}
