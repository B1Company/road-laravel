<?php

declare(strict_types=1);

namespace B1Road\Laravel\Tests\Support;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * Builds a complete fake "Auth Server" surface for tests: an RSA keypair,
 * the OIDC discovery doc, the JWKS endpoint, and a signed ID token —
 * wired into Http::fake() so OidcProvider flows through without hitting
 * the network.
 */
final class OidcFixture
{
    public string $issuer = 'https://auth.test';

    public string $audience = 'road-api';

    public string $kid = 'road-test-key-1';

    public JWK $privateKey;

    public JWK $publicKey;

    /**
     * Mutable token-endpoint response. The Http::fake stub for
     * `/oauth/v2/token` reads this via a closure, so a test can call
     * fakeHttp() ONCE (before driving /login to learn the nonce) and
     * then set the real token payload before the callback — without a
     * second fakeHttp() call. Laravel's Http::fake() *merges* stub maps
     * across calls and the earliest matching stub wins, so calling it
     * twice would let an initial empty token response shadow the real
     * one. This indirection sidesteps that footgun entirely.
     *
     * @var array<string,mixed>
     */
    public array $tokenResponse = [];

    public function __construct()
    {
        $this->privateKey = JWKFactory::createRSAKey(
            2048,
            ['alg' => 'RS256', 'use' => 'sig', 'kid' => $this->kid]
        );
        $this->publicKey = $this->privateKey->toPublic();
    }

    /** @return array<string,mixed> */
    public function discoveryDoc(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer.'/oauth/v2/authorize',
            'token_endpoint' => $this->issuer.'/oauth/v2/token',
            'jwks_uri' => $this->issuer.'/oauth/v2/keys',
            'end_session_endpoint' => $this->issuer.'/oidc/v1/end_session',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post'],
        ];
    }

    /** @return array<string,mixed> */
    public function jwksDoc(): array
    {
        return ['keys' => [$this->publicKey->all()]];
    }

    /**
     * Sign an ID token JWS for the given user payload.
     *
     * @param  array<string,mixed>  $claims  Overrides merged on top of defaults.
     */
    public function issueIdToken(array $claims = []): string
    {
        $payload = array_merge([
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'sub' => 'u_test',
            'email' => 'test@example.com',
            'name' => 'Test User',
            'exp' => time() + 3600,
            'nbf' => time() - 5,
            'iat' => time(),
        ], $claims);

        $algorithmManager = new AlgorithmManager([new RS256]);
        $builder = new JWSBuilder($algorithmManager);

        $jws = $builder
            ->create()
            ->withPayload((string) json_encode($payload))
            ->addSignature($this->privateKey, ['alg' => 'RS256', 'kid' => $this->kid])
            ->build();

        return (new CompactSerializer)->serialize($jws);
    }

    /**
     * Wire up Http::fake() so OidcProvider/OidcDiscovery/JwksCache talk to
     * us instead of the network. The token-endpoint response is read
     * lazily from {@see $tokenResponse} at request time, so a test can
     * call this once and adjust the token payload later (after learning
     * the nonce from the login redirect) without a second fakeHttp()
     * call — see the property docblock for why that matters.
     *
     * @param  array<string,mixed>  $tokenResponse  Initial token payload (optional).
     */
    public function fakeHttp(array $tokenResponse = []): void
    {
        $this->tokenResponse = $tokenResponse;

        Http::fake([
            $this->issuer.'/.well-known/openid-configuration' => Http::response($this->discoveryDoc(), 200),
            $this->issuer.'/oauth/v2/keys' => Http::response($this->jwksDoc(), 200),
            $this->issuer.'/oauth/v2/token' => fn () => Http::response($this->tokenResponse, 200),
            // Catch-all so an unstubbed call surfaces clearly in tests.
            '*' => function (HttpRequest $request) {
                return Http::response(
                    ['error' => 'unstubbed_url_in_test', 'url' => $request->url()],
                    599,
                );
            },
        ]);
    }
}
