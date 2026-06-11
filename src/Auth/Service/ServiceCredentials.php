<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\Service;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Service-to-service credentials, in one of two flavours: a `client_secret`
 * (client_credentials grant) or a signed assertion (`private_key_jwt`).
 * Mirrors the `ServiceCredentials` union in road-nestjs.
 */
final class ServiceCredentials
{
    private function __construct(
        public readonly ServiceCredentialKind $kind,
        public readonly string $clientId,
        public readonly ?string $clientSecret = null,
        public readonly ?string $keyId = null,
        public readonly ?string $privateKey = null,
        public readonly string $algorithm = 'RS256',
    ) {}

    public static function clientCredentials(string $clientId, string $clientSecret): self
    {
        return new self(ServiceCredentialKind::ClientCredentials, $clientId, clientSecret: $clientSecret);
    }

    public static function privateKeyJwt(string $clientId, string $keyId, string $privateKey, string $algorithm = 'RS256'): self
    {
        return new self(
            ServiceCredentialKind::PrivateKeyJwt,
            $clientId,
            keyId: $keyId,
            privateKey: $privateKey,
            algorithm: $algorithm,
        );
    }

    /**
     * Build from `road.service.*` config, or null when service mode isn't
     * configured (so `Road::asService()` can raise a clear error).
     */
    public static function fromConfig(ConfigRepository $config): ?self
    {
        $clientId = (string) $config->get('road.service.client_id', '');
        if ($clientId === '') {
            return null;
        }

        if ($config->get('road.service.mode') === ServiceCredentialKind::PrivateKeyJwt->value) {
            $key = self::resolveKey((string) $config->get('road.service.private_key', ''));
            $keyId = (string) $config->get('road.service.key_id', '');
            if ($key === '' || $keyId === '') {
                return null;
            }

            return self::privateKeyJwt($clientId, $keyId, $key, (string) $config->get('road.service.algorithm', 'RS256'));
        }

        $secret = (string) $config->get('road.service.client_secret', '');
        if ($secret === '') {
            return null;
        }

        return self::clientCredentials($clientId, $secret);
    }

    /** Accept a PEM literal or a path to one. */
    private static function resolveKey(string $value): string
    {
        if ($value === '' || str_contains($value, 'BEGIN')) {
            return $value;
        }

        return is_file($value) ? (string) file_get_contents($value) : $value;
    }
}
