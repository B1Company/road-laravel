<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth;

use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Throwable;

/**
 * Verifies JWTs against the Auth Server's JWKS. Used at OIDC callback to
 * check the ID token, and (in follow-ups) to verify outbound proxy tokens.
 *
 * Throws RoadAuthnException on any failure — never leaks the underlying
 * web-token library's exception types.
 */
final class JwtValidator
{
    public function __construct(
        private readonly JwksCache $jwks,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Verify signature + standard claims (iss, aud, exp, nbf). Returns the
     * verified payload (claims) on success.
     *
     * @return array<string,mixed>
     */
    public function verify(string $token): array
    {
        try {
            $jws = (new JWSSerializerManager([new CompactSerializer()]))->unserialize($token);
        } catch (Throwable $e) {
            throw new RoadAuthnException(
                message: 'JWT is malformed.',
                errorCode: 'invalid_token',
                previous: $e,
            );
        }

        $verifier = new JWSVerifier(new AlgorithmManager([new RS256(), new ES256()]));
        $jwks = $this->jwks->get();

        if (! $verifier->verifyWithKeySet($jws, $jwks, 0)) {
            throw new RoadAuthnException(
                message: 'JWT signature verification failed.',
                errorCode: 'invalid_token',
            );
        }

        $payload = json_decode((string) $jws->getPayload(), associative: true);
        if (! is_array($payload)) {
            throw new RoadAuthnException(
                message: 'JWT payload is not a JSON object.',
                errorCode: 'invalid_token',
            );
        }

        $this->assertClaims($payload);

        return $payload;
    }

    /** @param  array<string,mixed>  $payload */
    private function assertClaims(array $payload): void
    {
        $expectedIssuer = (string) $this->config->get('road.auth_server.issuer_url', '');
        $expectedAudience = (string) $this->config->get('road.auth_server.audience', '');

        $issuer = isset($payload['iss']) ? (string) $payload['iss'] : '';
        if ($expectedIssuer !== '' && rtrim($issuer, '/') !== rtrim($expectedIssuer, '/')) {
            throw new RoadAuthnException(
                message: sprintf('JWT issuer mismatch: expected %s, got %s.', $expectedIssuer, $issuer),
                errorCode: 'invalid_token',
            );
        }

        if ($expectedAudience !== '') {
            $aud = $payload['aud'] ?? null;
            $audValid = is_string($aud)
                ? $aud === $expectedAudience
                : (is_array($aud) && in_array($expectedAudience, $aud, true));
            if (! $audValid) {
                throw new RoadAuthnException(
                    message: sprintf('JWT audience does not include %s.', $expectedAudience),
                    errorCode: 'invalid_token',
                );
            }
        }

        $now = time();
        if (! isset($payload['exp']) || (int) $payload['exp'] < $now) {
            throw new RoadAuthnException(
                message: 'JWT is expired.',
                errorCode: 'expired_token',
            );
        }
        if (isset($payload['nbf']) && (int) $payload['nbf'] > $now + 5) {
            throw new RoadAuthnException(
                message: 'JWT is not yet valid (nbf in the future).',
                errorCode: 'invalid_token',
            );
        }
    }
}
