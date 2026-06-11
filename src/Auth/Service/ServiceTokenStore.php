<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\Service;

use B1Road\Laravel\Auth\AuthServer\OidcDiscovery;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Throwable;

/**
 * Acquires and caches a service-to-service access token from the Auth Server's
 * OAuth2 token endpoint — `client_credentials` or `private_key_jwt`. Cached in
 * the application cache (so it survives across queue workers) until `exp − 60s`,
 * with a lock serialising concurrent acquisition. Port of road-nestjs's
 * `ServiceTokenStore`.
 */
final class ServiceTokenStore
{
    /** Refresh this many seconds before the token actually expires. */
    private const EXPIRY_SKEW_SECONDS = 60;

    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
        private readonly CacheRepository $cache,
        private readonly ServiceCredentials $credentials,
    ) {}

    public function getToken(): string
    {
        $cached = $this->cache->get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        // Serialise acquisition so concurrent workers don't stampede the token
        // endpoint. Locks live on the LockProvider store, not the cache
        // contract — degrade to a direct acquire on drivers that can't lock.
        $store = $this->cache instanceof Repository ? $this->cache->getStore() : null;
        if ($store instanceof LockProvider) {
            return $store->lock($this->cacheKey().':lock', 10)->block(5, fn (): string => $this->acquireAndCache());
        }

        return $this->acquireAndCache();
    }

    public function invalidate(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    private function acquireAndCache(): string
    {
        // Re-check inside the lock — another worker may have just filled it.
        $cached = $this->cache->get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$token, $expiresIn] = $this->acquire();
        $this->cache->put($this->cacheKey(), $token, max(1, $expiresIn - self::EXPIRY_SKEW_SECONDS));

        return $token;
    }

    /** @return array{0: string, 1: int} [token, expiresIn] */
    private function acquire(): array
    {
        try {
            $response = $this->http->asForm()->timeout(10)->post($this->discovery->tokenEndpoint(), $this->buildForm());
        } catch (Throwable $e) {
            throw new RoadNetworkException(
                message: 'Failed to reach the Auth Server token endpoint for a service token.',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RoadAuthnException(
                message: sprintf('Service token acquisition failed (HTTP %d).', $response->status()),
                errorCode: 'service_token_failed',
            );
        }

        $data = $response->json();
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;
        $expiresIn = is_array($data) ? ($data['expires_in'] ?? null) : null;

        if (! is_string($token) || $token === '' || ! is_numeric($expiresIn) || (int) $expiresIn <= 0) {
            throw new RoadAuthnException(
                message: 'Auth Server returned a service-token response with an unexpected shape (missing access_token / expires_in).',
                errorCode: 'service_token_failed',
            );
        }

        return [$token, (int) $expiresIn];
    }

    /** @return array<string,string> */
    private function buildForm(): array
    {
        $form = [
            'grant_type' => 'client_credentials',
            'scope' => $this->scope(),
        ];

        if ($this->credentials->kind === ServiceCredentialKind::ClientCredentials) {
            $form['client_id'] = $this->credentials->clientId;
            $form['client_secret'] = (string) $this->credentials->clientSecret;

            return $form;
        }

        $form['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
        $form['client_assertion'] = $this->buildAssertion();

        return $form;
    }

    private function scope(): string
    {
        $audience = (string) ($this->config->get('road.service.audience') ?? $this->config->get('road.auth_server.audience', ''));

        // Mirror road-nestjs: request the project-audience'd scope when an
        // audience is configured, else plain `openid`.
        return $audience !== ''
            ? 'openid urn:zitadel:iam:org:project:id:'.$audience.':aud'
            : 'openid';
    }

    /** Build a signed `private_key_jwt` client assertion. */
    private function buildAssertion(): string
    {
        $algorithm = $this->credentials->algorithm;
        $keyId = (string) $this->credentials->keyId;

        $jwk = JWKFactory::createFromKey((string) $this->credentials->privateKey, null, [
            'use' => 'sig',
            'alg' => $algorithm,
            'kid' => $keyId,
        ]);

        $now = time();
        $issuer = rtrim($this->discovery->issuerUrl(), '/');
        $payload = json_encode([
            'iss' => $this->credentials->clientId,
            'sub' => $this->credentials->clientId,
            'aud' => $issuer,
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => (string) Str::uuid(),
        ], JSON_THROW_ON_ERROR);

        $builder = new JWSBuilder(new AlgorithmManager([new RS256, new ES256]));
        $jws = $builder->create()
            ->withPayload($payload)
            ->addSignature($jwk, ['alg' => $algorithm, 'kid' => $keyId, 'typ' => 'JWT'])
            ->build();

        return (new CompactSerializer)->serialize($jws, 0);
    }

    private function cacheKey(): string
    {
        return 'road.service.token:'.$this->credentials->clientId;
    }
}
