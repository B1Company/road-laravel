<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Jose\Component\Core\JWKSet;
use Throwable;

/**
 * Caches the Auth Server's JWKS (JSON Web Key Set). Wraps Laravel's cache
 * abstraction so `road:cache:clear` (follow-up) can bust it.
 */
final class JwksCache
{
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly CacheRepository $cache,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function get(): JWKSet
    {
        $url = $this->discovery->jwksUri();
        $ttl = (int) $this->config->get('road.api.jwks_ttl', 600);

        /** @var array<string,mixed> $data */
        $data = $this->cache->remember(
            $this->cacheKey($url),
            $ttl,
            fn (): array => $this->fetch($url),
        );

        return JWKSet::createFromKeyData($data);
    }

    /**
     * Bust the cached JWKS and re-fetch immediately. Called by
     * `JwtValidator` when signature verification fails — handles the
     * case where the Auth Server rotated keys mid-cache and the
     * incoming JWT was signed with a kid we haven't seen yet.
     */
    public function refresh(): JWKSet
    {
        $url = $this->discovery->jwksUri();
        $this->cache->forget($this->cacheKey($url));

        return $this->get();
    }

    private function cacheKey(string $url): string
    {
        return 'road.oidc.jwks:'.$url;
    }

    /** @return array<string,mixed> */
    private function fetch(string $url): array
    {
        try {
            $response = $this->http->timeout(5)->get($url);
        } catch (Throwable $e) {
            throw new RoadAuthnException(
                message: 'Failed to fetch Auth Server JWKS.',
                errorCode: 'jwks_unreachable',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RoadAuthnException(
                message: sprintf('Auth Server JWKS endpoint returned HTTP %d.', $response->status()),
                errorCode: 'jwks_fetch_failed',
            );
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['keys']) || ! is_array($data['keys'])) {
            throw new RoadAuthnException(
                message: 'Auth Server JWKS response is malformed (missing `keys`).',
                errorCode: 'jwks_invalid',
            );
        }

        return $data;
    }
}
