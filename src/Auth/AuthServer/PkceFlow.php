<?php

declare(strict_types=1);

namespace B1Road\Laravel\Auth\AuthServer;

use B1Road\Laravel\Exceptions\RoadAuthnException;
use Illuminate\Contracts\Session\Session;

/**
 * RFC 7636 PKCE helper. Generates `verifier`, derives `challenge` (S256),
 * generates `state` and `nonce`, persists them under a namespaced session
 * key for the callback to read.
 */
final class PkceFlow
{
    private const SESSION_KEY = 'road.oidc.pkce';

    public function __construct(private readonly Session $session)
    {
    }

    public function start(): PkceCodes
    {
        $verifier = self::base64UrlEncode(random_bytes(64));
        $challenge = self::base64UrlEncode(hash('sha256', $verifier, true));
        $state = self::base64UrlEncode(random_bytes(32));
        $nonce = self::base64UrlEncode(random_bytes(32));

        $this->session->put(self::SESSION_KEY, [
            'verifier' => $verifier,
            'state'    => $state,
            'nonce'    => $nonce,
        ]);
        $this->session->save();

        return new PkceCodes(
            verifier: $verifier,
            challenge: $challenge,
            state: $state,
            nonce: $nonce,
        );
    }

    /**
     * Validate the callback's `state` against the value we persisted at
     * `start()`, and return the matched verifier+nonce.
     */
    public function consume(string $stateFromCallback): PkceCodes
    {
        $stored = $this->session->pull(self::SESSION_KEY);
        if (! is_array($stored) || ! isset($stored['state'], $stored['verifier'], $stored['nonce'])) {
            throw new RoadAuthnException(
                message: 'No pending OIDC login found in session. Restart the login flow.',
                errorCode: 'oidc_state_missing',
            );
        }

        if (! hash_equals((string) $stored['state'], $stateFromCallback)) {
            throw new RoadAuthnException(
                message: 'OIDC state mismatch. Possible CSRF or stale session.',
                errorCode: 'oidc_state_mismatch',
            );
        }

        return new PkceCodes(
            verifier: (string) $stored['verifier'],
            challenge: '',
            state: (string) $stored['state'],
            nonce: (string) $stored['nonce'],
        );
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
