<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

use B1Road\Laravel\Auth\JwtValidator;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Orchestrates the OIDC PKCE flow against the Auth Server.
 *
 * Mirrors the shape `apps/sdks/road-nestjs/src/auth/` will adopt under
 * plan 09 (NestJS BFF refactor) — Laravel ships first, NestJS mirrors.
 */
final class OidcProvider
{
    public function __construct(
        private readonly OidcDiscovery $discovery,
        private readonly PkceFlow $pkce,
        private readonly TokenStore $tokenStore,
        private readonly JwtValidator $jwt,
        private readonly ConfigRepository $config,
        private readonly HttpFactory $http,
    ) {}

    public function redirectToLogin(Request $request): RedirectResponse
    {
        $codes = $this->pkce->start();

        $intended = $request->query('intended');
        if (is_string($intended) && $intended !== '') {
            $request->session()->put('road.intended_url', $intended);
        }

        $params = [
            'response_type' => 'code',
            'client_id' => $this->requireConfig('road.auth_server.client_id'),
            'redirect_uri' => $this->requireConfig('road.auth_server.redirect_uri'),
            'scope' => implode(' ', (array) $this->config->get('road.auth_server.scopes', ['openid'])),
            'state' => $codes->state,
            'nonce' => $codes->nonce,
            'code_challenge' => $codes->challenge,
            'code_challenge_method' => 'S256',
        ];

        // Audience is optional; the Auth Server uses it to scope the access token
        // to a specific project. Pass-through if configured.
        $audience = $this->config->get('road.auth_server.audience');
        if (is_string($audience) && $audience !== '') {
            $params['audience'] = $audience;
        }

        return new RedirectResponse(
            $this->discovery->authorizationEndpoint().'?'.http_build_query($params)
        );
    }

    public function handleCallback(Request $request): RoadUser
    {
        $code = $request->query('code');
        $state = $request->query('state');

        if (! is_string($code) || ! is_string($state)) {
            throw new RoadAuthnException(
                message: 'OIDC callback missing `code` or `state` query params.',
                errorCode: 'oidc_callback_invalid',
            );
        }

        $codes = $this->pkce->consume($state);
        $tokens = $this->exchangeCode($code, $codes->verifier);
        $idClaims = $this->verifyIdToken($tokens, $codes->nonce);

        $tokenSet = $this->buildTokenSet($tokens, $idClaims, fallbackRefresh: null);
        $this->tokenStore->put($tokenSet);

        return self::payloadToUser($idClaims);
    }

    public function logout(Request $request): RedirectResponse
    {
        $tokens = $this->tokenStore->get();
        $idToken = $tokens?->idToken;

        // Revoke the access token at the Road API before clearing the store:
        // POST /me/logout stamps the revocation watermark, so the JWT stops
        // working at Road's door immediately instead of surviving until
        // natural expiry (plan 47). Best-effort — a failing revocation must
        // never trap the user in a session they are trying to leave.
        // NOTE: /me/logout terminates ALL of the user's sessions (no
        // per-session revocation — the JWT carries no sid).
        if ($tokens?->accessToken !== null && $tokens->accessToken !== '') {
            try {
                $baseUrl = rtrim((string) $this->config->get('road.api.base_url', ''), '/');
                $version = (string) $this->config->get('road.api.version', 'alpha');
                if ($baseUrl !== '') {
                    $this->http
                        ->withToken($tokens->accessToken)
                        ->timeout(5)
                        ->post("{$baseUrl}/api/{$version}/iam/identity/me/logout");
                }
            } catch (Throwable) {
                // Swallow: network failures at logout time are not the user's problem.
            }
        }

        $this->tokenStore->clear();
        $request->session()->forget('road.oidc.pkce');

        $endSession = $this->discovery->endSessionEndpoint();
        if ($endSession === null) {
            return new RedirectResponse($request->root());
        }

        $params = array_filter([
            'id_token_hint' => $idToken,
            'post_logout_redirect_uri' => $request->root(),
        ], fn (?string $v) => $v !== null && $v !== '');

        return new RedirectResponse($endSession.'?'.http_build_query($params));
    }

    public function refresh(string $refreshToken): TokenSet
    {
        try {
            $response = $this->http->asForm()->timeout(10)->post(
                $this->discovery->tokenEndpoint(),
                [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $this->requireConfig('road.auth_server.client_id'),
                    'client_secret' => $this->requireConfig('road.auth_server.client_secret'),
                ]
            );
        } catch (Throwable $e) {
            throw new RoadAuthnException(
                message: 'Token refresh request failed.',
                errorCode: 'oidc_refresh_failed',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RoadAuthnException(
                message: sprintf('Token refresh returned HTTP %d.', $response->status()),
                errorCode: 'oidc_refresh_failed',
            );
        }

        /** @var array<string,mixed> $tokens */
        $tokens = (array) $response->json();
        $idClaims = $this->verifyIdToken($tokens, expectedNonce: null);

        $tokenSet = $this->buildTokenSet($tokens, $idClaims, fallbackRefresh: $refreshToken);
        $this->tokenStore->put($tokenSet);

        return $tokenSet;
    }

    // -- internals ---------------------------------------------------------

    /** @return array<string,mixed> */
    private function exchangeCode(string $code, string $verifier): array
    {
        try {
            $response = $this->http->asForm()->timeout(10)->post(
                $this->discovery->tokenEndpoint(),
                [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->requireConfig('road.auth_server.redirect_uri'),
                    'client_id' => $this->requireConfig('road.auth_server.client_id'),
                    'client_secret' => $this->requireConfig('road.auth_server.client_secret'),
                    'code_verifier' => $verifier,
                ]
            );
        } catch (Throwable $e) {
            throw new RoadAuthnException(
                message: 'OIDC token exchange failed (network).',
                errorCode: 'oidc_token_exchange_failed',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new RoadAuthnException(
                message: sprintf('OIDC token exchange returned HTTP %d.', $response->status()),
                errorCode: 'oidc_token_exchange_failed',
                payload: ['response' => (string) $response->body()],
            );
        }

        /** @var array<string,mixed> $tokens */
        $tokens = (array) $response->json();
        if (! isset($tokens['access_token'])) {
            throw new RoadAuthnException(
                message: 'OIDC token response missing `access_token`.',
                errorCode: 'oidc_token_exchange_failed',
            );
        }

        return $tokens;
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @return array<string,mixed>
     */
    private function verifyIdToken(array $tokens, ?string $expectedNonce): array
    {
        $idToken = $tokens['id_token'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            throw new RoadAuthnException(
                message: 'OIDC token response missing `id_token`.',
                errorCode: 'oidc_id_token_missing',
            );
        }

        $claims = $this->jwt->verify($idToken);

        if ($expectedNonce !== null) {
            $nonce = isset($claims['nonce']) ? (string) $claims['nonce'] : '';
            if (! hash_equals($expectedNonce, $nonce)) {
                throw new RoadAuthnException(
                    message: 'OIDC ID token nonce mismatch.',
                    errorCode: 'oidc_nonce_mismatch',
                );
            }
        }

        return $claims;
    }

    /**
     * @param  array<string,mixed>  $tokens
     * @param  array<string,mixed>  $idClaims
     */
    private function buildTokenSet(array $tokens, array $idClaims, ?string $fallbackRefresh): TokenSet
    {
        $expiresIn = isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : 3600;
        $refresh = $tokens['refresh_token'] ?? $fallbackRefresh;

        return new TokenSet(
            accessToken: (string) $tokens['access_token'],
            refreshToken: is_string($refresh) && $refresh !== '' ? $refresh : null,
            idToken: isset($tokens['id_token']) ? (string) $tokens['id_token'] : null,
            expiresAt: time() + $expiresIn,
            userPayload: $idClaims,
        );
    }

    /** @param  array<string,mixed>  $payload */
    private static function payloadToUser(array $payload): RoadUser
    {
        return new RoadUser(
            id: (string) ($payload['sub'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            name: (string) ($payload['name'] ?? $payload['preferred_username'] ?? ''),
            avatarUrl: isset($payload['picture']) ? (string) $payload['picture'] : null,
            payload: $payload,
        );
    }

    private function requireConfig(string $key): string
    {
        $value = $this->config->get($key);
        if (! is_string($value) || $value === '') {
            throw new RoadAuthnException(
                message: sprintf('Configuration `%s` is not set.', $key),
                errorCode: 'auth_server_misconfigured',
            );
        }

        return $value;
    }
}
