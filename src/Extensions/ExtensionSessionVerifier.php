<?php

declare(strict_types=1);

namespace B1Road\Laravel\Extensions;

use B1Road\Laravel\Exceptions\RoadExtensionSessionException;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Verifies the embed session context an extension's iframe forwards to its
 * backend — the Laravel counterpart of `verifyExtensionSession` in the Node
 * SDKs, with the same checks, the same order and the same error codes.
 *
 * Road signs the context with the extension's `signing` secret; the host page
 * and the iframe only carry it. So nothing in it is true until this backend,
 * the only other holder of that secret, has checked it:
 *
 * 1. `{ payload, signature }` are the base64url string and the 64-hex digest Road issued;
 * 2. HMAC-SHA256 of `payload` under the secret matches, compared in constant time;
 * 3. the format version is 1;
 * 4. every field is present;
 * 5. the context is inside its window (`issuedAt` to `expiresAt`, 30 seconds of
 *    clock skew either side, capped by `maxAgeSeconds`);
 * 6. when given, it is for `expectInstall`.
 *
 * The decoded copy Road also returns (`context`) is never read, and
 * `embedAssertion` is not covered by the signature: Road checks it when you
 * send it as `embed_assertion` on the token exchange, so pass it through.
 *
 * ```php
 * // routes/api.php
 * Route::post('/embed-session', function (Request $request) {
 *     // road_rotate_extension_secret (kind='signing') writes it to .env.
 *     $verifier = new ExtensionSessionVerifier((string) env('ROAD_EXTENSION_SIGNING_SECRET_EXT_ABC123'));
 *     try {
 *         $session = $verifier->verify($request->json()->all());
 *     } catch (RoadExtensionSessionException $e) {
 *         return response()->json(['code' => $e->errorCode()], 401);
 *     }
 *     // $session->userId, $session->installId, $session->businessUnitId
 *     return ['userId' => $session->userId];
 * });
 * ```
 */
final class ExtensionSessionVerifier
{
    /** The only format version this verifier reads. */
    private const SUPPORTED_VERSION = 1;

    /** Road's own validity: `expiresAt` is `issuedAt` plus 5 minutes. */
    private const DEFAULT_MAX_AGE_SECONDS = 300;

    /** Clock difference tolerated between Road and this server, either side. */
    private const CLOCK_SKEW_MS = 30_000;

    /**
     * @param  string  $secret  The extension's `signing` secret. Rotating it invalidates the old one.
     */
    public function __construct(private readonly string $secret)
    {
        // An empty key would make an HMAC anyone can compute: refuse it outright.
        if ($secret === '') {
            throw new InvalidArgumentException(
                "ExtensionSessionVerifier: the secret is empty. Pass the extension's signing secret "
                ."(road_rotate_extension_secret with kind='signing' writes it to .env); a missing "
                .'environment variable is the usual cause.'
            );
        }
    }

    /**
     * @param  array<array-key, mixed>  $signed  What the iframe forwarded: `signed` from
     *                                           `useExtensionHost().getSessionContext()`.
     * @param  DateTimeInterface|null  $now  The moment to verify at. Default: now.
     * @param  string|null  $expectInstall  The install (`exti_…`) this request must be for.
     * @param  int|null  $maxAgeSeconds  The oldest context accepted, from `issuedAt`. Default 300,
     *                                   Road's own validity; never extends past `expiresAt`.
     *
     * @throws RoadExtensionSessionException when the context is refused.
     * @throws InvalidArgumentException when an argument is not usable.
     */
    public function verify(
        array $signed,
        ?DateTimeInterface $now = null,
        ?string $expectInstall = null,
        ?int $maxAgeSeconds = null,
    ): ExtensionSession {
        $maxAgeSeconds ??= self::DEFAULT_MAX_AGE_SECONDS;
        if ($maxAgeSeconds < 0) {
            throw new InvalidArgumentException("ExtensionSessionVerifier: maxAgeSeconds must not be negative; got {$maxAgeSeconds}.");
        }
        if ($expectInstall === '') {
            throw new InvalidArgumentException(
                'ExtensionSessionVerifier: expectInstall must be an install id (exti_…) when given. '
                .'Leave it out to accept any install.'
            );
        }
        $nowMs = self::toMs($now ?? new DateTimeImmutable);

        $payload = $signed['payload'] ?? null;
        if (! is_string($payload) || preg_match('/^[A-Za-z0-9_-]+$/', $payload) !== 1) {
            throw $this->refuse(
                RoadExtensionSessionException::MALFORMED,
                "The session context's `payload` is not the base64url string Road signed. "
                .'Forward it exactly as it came: decoding or re-encoding it on the way breaks the signature.'
            );
        }
        // Checked before comparing, so hash_equals always compares two
        // 64-character ASCII strings.
        $signature = is_string($signed['signature'] ?? null) ? strtolower($signed['signature']) : '';
        if (preg_match('/^[0-9a-f]{64}$/', $signature) !== 1) {
            throw $this->refuse(
                RoadExtensionSessionException::MALFORMED,
                "The session context's `signature` is not a 64-character hex HMAC-SHA256 digest. "
                .'Forward it exactly as it came.'
            );
        }

        if (! hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) {
            throw $this->refuse(
                RoadExtensionSessionException::SIGNATURE_MISMATCH,
                "The session context's signature does not match its payload: it was altered on "
                ."the way, or this backend is not using the extension's current signing secret. "
                ."A rotation (road_rotate_extension_secret, kind='signing') invalidates the old one."
            );
        }

        $decoded = self::decode($payload);
        if ($decoded === null) {
            throw $this->refuse(
                RoadExtensionSessionException::MALFORMED,
                'The session context is signed but its payload does not decode to a JSON object.'
            );
        }
        if (($decoded['v'] ?? null) !== self::SUPPORTED_VERSION) {
            throw $this->refuse(
                RoadExtensionSessionException::UNSUPPORTED_VERSION,
                'The session context is version '.json_encode($decoded['v'] ?? null).', and this SDK reads '
                .'version '.self::SUPPORTED_VERSION.'. Update b1-road/laravel to a release that reads it.'
            );
        }

        $user = is_array($decoded['user'] ?? null) ? $decoded['user'] : [];
        $install = is_array($decoded['install'] ?? null) ? $decoded['install'] : [];
        $fields = [
            'user.id' => $user['id'] ?? null,
            'install.id' => $install['id'] ?? null,
            'install.extension' => $install['extension'] ?? null,
            'install.businessUnitId' => $install['businessUnitId'] ?? null,
        ];
        $missing = array_keys(array_filter($fields, fn ($v) => ! is_string($v) || $v === ''));
        $issuedAt = self::instant($decoded['issuedAt'] ?? null);
        $expiresAt = self::instant($decoded['expiresAt'] ?? null);
        if ($issuedAt === null) {
            $missing[] = 'issuedAt';
        }
        if ($expiresAt === null) {
            $missing[] = 'expiresAt';
        }
        if ($missing !== [] || $issuedAt === null || $expiresAt === null) {
            throw $this->refuse(
                RoadExtensionSessionException::MALFORMED,
                'The session context is signed but lacks '.implode(', ', $missing).'.'
            );
        }

        $issuedMs = self::toMs($issuedAt);
        $expiresMs = self::toMs($expiresAt);
        if ($issuedMs - self::CLOCK_SKEW_MS > $nowMs) {
            throw $this->refuse(
                RoadExtensionSessionException::NOT_YET_VALID,
                "The session context was issued at {$decoded['issuedAt']}, more than 30 seconds ahead "
                ."of this server's clock (".self::iso($nowMs)."). Check this server's clock."
            );
        }
        $deadlineMs = min($expiresMs, $issuedMs + $maxAgeSeconds * 1000);
        if ($nowMs > $deadlineMs + self::CLOCK_SKEW_MS) {
            throw $this->refuse(
                RoadExtensionSessionException::EXPIRED,
                'The session context expired at '.self::iso($deadlineMs).'; it is '.self::iso($nowMs).' here. '
                .'A context lives 5 minutes: have the iframe ask the host for a fresh one with '
                .'getSessionContext() instead of reusing one.'
            );
        }

        /** @var array{'user.id': string, 'install.id': string, 'install.extension': string, 'install.businessUnitId': string} $fields */
        if ($expectInstall !== null && $fields['install.id'] !== $expectInstall) {
            throw $this->refuse(
                RoadExtensionSessionException::INSTALL_MISMATCH,
                "The session context is for install {$fields['install.id']}, and this request expects "
                ."{$expectInstall}. Refuse it: the context names the install the person is using."
            );
        }

        return new ExtensionSession(
            userId: $fields['user.id'],
            installId: $fields['install.id'],
            extensionId: $fields['install.extension'],
            businessUnitId: $fields['install.businessUnitId'],
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );
    }

    private function refuse(string $code, string $message): RoadExtensionSessionException
    {
        return new RoadExtensionSessionException($code, $message);
    }

    /** @return array<string, mixed>|null */
    private static function decode(string $payload): ?array
    {
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function toMs(DateTimeInterface $at): int
    {
        return (int) $at->format('Uv');
    }

    private static function iso(int $ms): string
    {
        return (new DateTimeImmutable('@'.intdiv($ms, 1000)))->format('Y-m-d\TH:i:s\Z');
    }
}
