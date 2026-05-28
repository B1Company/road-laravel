<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Auth\AuthServer\PkceFlow;
use B1Road\Laravel\Auth\AuthServer\SessionTokenStore;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\JwksCache;
use B1Road\Laravel\Auth\JwtValidator;
use B1Road\Laravel\Auth\RoadGuard;
use B1Road\Laravel\Auth\RoadUserProvider;
use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Console\DoctorCommand;
use B1Road\Laravel\Console\InstallCommand;
use B1Road\Laravel\Console\WhoamiCommand;
use B1Road\Laravel\Context\ContextResolver;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Http\Middleware\EnsureRoadAuthenticated;
use B1Road\Laravel\Http\Middleware\EnsureRoadAuthenticatedOptional;
use B1Road\Laravel\Http\Middleware\HandleRoadExceptions;
use B1Road\Laravel\Inertia\ShareRoadContext;
use B1Road\Laravel\Telemetry\NoopTelemetry;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Testing\FakeRoadClientFactory;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class RoadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/road.php', 'road');

        // Request-scoped — recreated on every request (Octane/FrankenPHP safe).
        $this->app->scoped(RoadContext::class, fn () => new RoadContext());

        $this->app->scoped(ContextResolver::class, function (Application $app): ContextResolver {
            return new ContextResolver(
                $app->make(TokenStore::class),
                $app->make(RoadContext::class),
            );
        });

        // TokenStore (MVP: session-backed only).
        $this->app->scoped(TokenStore::class, function (Application $app): TokenStore {
            return new SessionTokenStore($app->make(Session::class));
        });

        $this->app->scoped(PkceFlow::class, function (Application $app): PkceFlow {
            return new PkceFlow($app->make(Session::class));
        });

        $this->app->scoped(OidcDiscovery::class, function (Application $app): OidcDiscovery {
            return new OidcDiscovery(
                $app->make(ConfigRepository::class),
                $app->make(CacheRepository::class),
                $app->make(HttpFactory::class),
            );
        });

        $this->app->scoped(JwksCache::class, function (Application $app): JwksCache {
            return new JwksCache(
                $app->make(OidcDiscovery::class),
                $app->make(CacheRepository::class),
                $app->make(HttpFactory::class),
                $app->make(ConfigRepository::class),
            );
        });

        $this->app->scoped(JwtValidator::class, function (Application $app): JwtValidator {
            return new JwtValidator(
                $app->make(JwksCache::class),
                $app->make(ConfigRepository::class),
            );
        });

        $this->app->scoped(OidcProvider::class, function (Application $app): OidcProvider {
            return new OidcProvider(
                $app->make(OidcDiscovery::class),
                $app->make(PkceFlow::class),
                $app->make(TokenStore::class),
                $app->make(JwtValidator::class),
                $app->make(ConfigRepository::class),
                $app->make(HttpFactory::class),
            );
        });

        // The facade target.
        $this->app->singleton(RoadManager::class, function (Application $app): RoadManager {
            return new RoadManager($app, $app->make(RoadContext::class));
        });

        // Telemetry — default Noop. Integrators bind their own implementation
        // (Pulse, APM, custom log channel) in their AppServiceProvider.
        $this->app->singletonIf(RoadTelemetry::class, NoopTelemetry::class);

        $this->app->scoped(HttpTransport::class, function (Application $app): HttpTransport {
            return new HttpTransport(
                $app->make(HttpFactory::class),
                $app->make(RoadContext::class),
                $app->make(ConfigRepository::class),
                $app->make(RoadTelemetry::class),
            );
        });
        $this->app->scoped(HttpTransportInterface::class, fn (Application $app) => $app->make(HttpTransport::class));

        $this->app->scoped(RoadClient::class, function (Application $app): RoadClient {
            return new RoadClient($app->make(HttpTransportInterface::class));
        });

        // Test harness — the manager resolves this when Road::fake() is called.
        $this->app->singleton(FakeRoadClientFactory::class);
    }

    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->registerAuthGuard();
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');

        $config = $this->app->make(ConfigRepository::class);

        if ($config->get('road.proxy.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/proxy.php');
        }

        if ($this->shouldAutoMountInertia($config)) {
            $this->autoMountInertiaSharedProps();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/road.php' => config_path('road.php'),
            ], 'road-config');

            $this->publishes([
                __DIR__.'/../resources/js/road-inertia-provider.tsx'
                    => resource_path('js/lib/road-inertia-provider.tsx'),
            ], 'road-inertia');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                WhoamiCommand::class,
            ]);
        }
    }

    private function registerMiddlewareAliases(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('road', EnsureRoadAuthenticated::class);
        $router->aliasMiddleware('road.optional', EnsureRoadAuthenticatedOptional::class);
        $router->aliasMiddleware('road.errors', HandleRoadExceptions::class);
        $router->aliasMiddleware('road.inertia', ShareRoadContext::class);
    }

    private function registerAuthGuard(): void
    {
        /** @var AuthFactory $auth */
        $auth = $this->app->make(AuthFactory::class);

        $auth->provider('road', function (Application $app): RoadUserProvider {
            return new RoadUserProvider($app->make(RoadContext::class));
        });

        $auth->extend('road', function (Application $app, string $name, array $config): RoadGuard {
            $providerName = $config['provider'] ?? 'road';
            /** @var AuthFactory $auth */
            $auth = $app->make(AuthFactory::class);

            return new RoadGuard(
                $auth->createUserProvider($providerName) ?? new RoadUserProvider($app->make(RoadContext::class)),
                $app->make(RoadContext::class),
            );
        });
    }

    /**
     * Auto-mount only fires when Inertia is installed AND the integrator
     * has not opted out via `road.inertia.enabled=false`. The class_exists
     * probe keeps the SDK usable in non-Inertia Laravel apps without a
     * hard runtime dep.
     */
    private function shouldAutoMountInertia(ConfigRepository $config): bool
    {
        return (bool) $config->get('road.inertia.enabled', true)
            && class_exists(\Inertia\Inertia::class);
    }

    /**
     * Append `ShareRoadContext` to the `web` middleware group so every
     * Inertia render carries `props.road` automatically. Skips silently
     * on Laravel kernels that don't implement appendMiddlewareToGroup
     * (custom HTTP kernels in legacy apps); doctor's shared-props check
     * surfaces that case with a clear next step.
     *
     * `appendMiddlewareToGroup` is idempotent — re-appending is a no-op,
     * so this is safe across hot reloads and multi-provider boots.
     */
    private function autoMountInertiaSharedProps(): void
    {
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        if (! method_exists($kernel, 'appendMiddlewareToGroup')) {
            return;
        }
        $kernel->appendMiddlewareToGroup('web', ShareRoadContext::class);
    }
}
