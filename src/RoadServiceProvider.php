<?php

declare(strict_types=1);

namespace B1Road\Laravel;

use B1Road\Laravel\Auth\AuthServer\CacheTokenStore;
use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Auth\AuthServer\OidcProvider;
use B1Road\Laravel\Auth\AuthServer\PkceFlow;
use B1Road\Laravel\Auth\AuthServer\SessionTokenStore;
use B1Road\Laravel\Auth\AuthServer\TokenStore;
use B1Road\Laravel\Auth\JwksCache;
use B1Road\Laravel\Auth\JwtValidator;
use B1Road\Laravel\Auth\RoadGuard;
use B1Road\Laravel\Auth\RoadUserProvider;
use B1Road\Laravel\Bridges\GateBridge;
use B1Road\Laravel\Client\HttpTransport;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Console\DoctorCommand;
use B1Road\Laravel\Console\GenerateDtosCommand;
use B1Road\Laravel\Console\InstallCommand;
use B1Road\Laravel\Console\WhoamiCommand;
use B1Road\Laravel\Context\ContextResolver;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Http\Middleware\EnsureRoadAuthenticated;
use B1Road\Laravel\Http\Middleware\EnsureRoadAuthenticatedOptional;
use B1Road\Laravel\Http\Middleware\HandleRoadExceptions;
use B1Road\Laravel\Http\Middleware\RequirePermission;
use B1Road\Laravel\Http\Middleware\ResolveAttributePermissions;
use B1Road\Laravel\Http\Middleware\VerifyRoadWebhookSignature;
use B1Road\Laravel\Inertia\ShareRoadContext;
use B1Road\Laravel\Telemetry\NoopTelemetry;
use B1Road\Laravel\Telemetry\RoadTelemetry;
use B1Road\Laravel\Testing\FakeRoadClientFactory;
use B1Road\Laravel\Webhooks\WebhookSignatureVerifier;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class RoadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/road.php', 'road');

        // Request-scoped — recreated on every request (Octane/FrankenPHP safe).
        $this->app->scoped(RoadContext::class, fn () => new RoadContext);

        $this->app->scoped(ContextResolver::class, function (Application $app): ContextResolver {
            return new ContextResolver(
                $app->make(TokenStore::class),
                $app->make(RoadContext::class),
            );
        });

        // TokenStore — `session` (default) keeps the BFF tokens in the session
        // payload; `cache` keeps them in a shared cache (Redis) keyed by session
        // id, for horizontally-scaled / Octane BFFs.
        $this->app->scoped(TokenStore::class, function (Application $app): TokenStore {
            $config = $app->make(ConfigRepository::class);
            if ($config->get('road.token_store', 'session') === 'cache') {
                return new CacheTokenStore(
                    $app->make(CacheRepository::class),
                    $app->make(Session::class),
                    (int) $config->get('session.lifetime', 120) * 60,
                );
            }

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

        // Webhook signature verifier, configured from the endpoint secret —
        // available for integrators who verify deliveries by hand.
        $this->app->scoped(WebhookSignatureVerifier::class, function (Application $app): WebhookSignatureVerifier {
            $config = $app->make(ConfigRepository::class);

            return new WebhookSignatureVerifier(
                (string) $config->get('road.webhooks.secret', ''),
                (int) $config->get('road.webhooks.tolerance', 300),
            );
        });
    }

    public function boot(): void
    {
        $this->registerMiddlewareAliases();
        $this->registerExceptionRendering();
        $this->registerAuthGuard();
        $this->loadRoutesFrom(__DIR__.'/../routes/auth.php');

        $config = $this->app->make(ConfigRepository::class);

        // Fail loud at boot on a production misconfiguration that would otherwise
        // 401/500 every request silently. No-op outside production.
        BootGuards::assert($this->app, $config);

        if ($config->get('road.proxy.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/proxy.php');
        }

        if ($config->get('road.webhooks.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->shouldAutoMountInertia($config)) {
            $this->autoMountInertiaSharedProps();
        }

        if ($config->get('road.bridges.gate', false)) {
            GateBridge::register($this->app->make(GateContract::class));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/road.php' => config_path('road.php'),
            ], 'road-config');

            $this->publishes([
                __DIR__.'/../resources/js/road-inertia-provider.tsx' => resource_path('js/lib/road-inertia-provider.tsx'),
            ], 'road-inertia');

            $this->commands([
                InstallCommand::class,
                DoctorCommand::class,
                WhoamiCommand::class,
                GenerateDtosCommand::class,
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
        $router->aliasMiddleware('road.permission', RequirePermission::class);
        $router->aliasMiddleware('road.permission.attribute', ResolveAttributePermissions::class);
        $router->aliasMiddleware('road.webhook', VerifyRoadWebhookSignature::class);
    }

    /**
     * Register the RoadException → response mapping on the framework
     * exception handler. This — not the `road.errors` middleware — is
     * what actually converts a RoadException into a 401 JSON body or a
     * login redirect, because Laravel's routing pipeline renders
     * downstream exceptions via the handler before they can reach an
     * earlier middleware's catch (see HandleRoadExceptions docblock).
     *
     * No-ops on exception handlers that don't support `renderable`
     * (custom handlers in unusual host apps); the middleware then
     * remains the fallback for in-pipeline throws.
     */
    private function registerExceptionRendering(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $app = $this->app;
        $handler->renderable(static function (RoadException $e, Request $request) use ($app): Response {
            return $app->make(HandleRoadExceptions::class)->render($request, $e);
        });
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
            && class_exists(Inertia::class);
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
        $kernel = $this->app->make(Kernel::class);
        if (! method_exists($kernel, 'appendMiddlewareToGroup')) {
            return;
        }
        $kernel->appendMiddlewareToGroup('web', ShareRoadContext::class);
    }
}
