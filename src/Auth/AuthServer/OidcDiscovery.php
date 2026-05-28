<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * Fetches and caches the Auth Server's OIDC discovery document.
 *
 * Refresh TTL: `road.api.jwks_ttl` (default 600s).
 * Cache busting: `php artisan road:cache:clear` (follow-up).
 */
final class OidcDiscovery
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
    ) {
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        $issuer = $this->issuerUrl();
        $ttl    = (int) $this->config->get('road.api.jwks_ttl', 600);

        $cached = $this->cache->remember(
            'road.oidc.discovery:'.$issuer,
            $ttl,
            function () use ($issuer): array {
                $url = rtrim($issuer, '/').'/.well-known/openid-configuration';

                try {
                    $response = $this->http->timeout(5)->get($url);
                } catch (Throwable $e) {
                    throw new RoadAuthnException(
                        message: 'Failed to fetch Auth Server discovery document.',
                        errorCode: 'auth_server_unreachable',
                        previous: $e,
                    );
                }

                if (! $response->successful()) {
                    throw new RoadAuthnException(
                        message: sprintf(
                            'Auth Server discovery returned HTTP %d.',
                            $response->status(),
                        ),
                        errorCode: 'auth_server_discovery_failed',
                    );
                }

                $data = $response->json();
                if (! is_array($data) || ! isset($data['jwks_uri'], $data['authorization_endpoint'], $data['token_endpoint'])) {
                    throw new RoadAuthnException(
                        message: 'Auth Server discovery document is missing required endpoints.',
                        errorCode: 'auth_server_discovery_invalid',
                    );
                }

                return $data;
            }
        );

        return $cached;
    }

    public function authorizationEndpoint(): string
    {
        return (string) $this->metadata()['authorization_endpoint'];
    }

    public function tokenEndpoint(): string
    {
        return (string) $this->metadata()['token_endpoint'];
    }

    public function jwksUri(): string
    {
        return (string) $this->metadata()['jwks_uri'];
    }

    public function endSessionEndpoint(): ?string
    {
        $meta = $this->metadata();

        return isset($meta['end_session_endpoint'])
            ? (string) $meta['end_session_endpoint']
            : null;
    }

    public function issuerUrl(): string
    {
        $issuer = $this->config->get('road.auth_server.issuer_url');
        if (! is_string($issuer) || $issuer === '') {
            throw new RoadAuthnException(
                message: 'road.auth_server.issuer_url is not configured.',
                errorCode: 'auth_server_misconfigured',
            );
        }

        return $issuer;
    }
}
