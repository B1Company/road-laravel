<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks;

/**
 * Verifies a Road webhook delivery's HMAC-SHA256 signature. Framework-free
 * and constant-time so it's unit-testable and safe.
 *
 * The Road API signs `"{timestamp}.{rawBody}"` with the endpoint's secret and
 * sends the digest as `X-Road-Signature: sha256=<hex>` alongside an
 * `X-Road-Timestamp` (epoch seconds). We strip the `sha256=` prefix before
 * comparing — the bare-hex form the API actually puts on the wire.
 */
final class WebhookSignatureVerifier
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {}

    /**
     * @param  string  $rawBody  The exact request body bytes (never re-encoded).
     */
    public function verify(string $rawBody, ?string $signatureHeader, ?string $timestampHeader): bool
    {
        if ($signatureHeader === null || $signatureHeader === '' || $timestampHeader === null || $timestampHeader === '') {
            return false;
        }

        if (! is_numeric($timestampHeader)) {
            return false;
        }

        // Accept seconds (what the API sends) or millis, like the other SDKs.
        $ts = (int) $timestampHeader;
        $tsMs = $ts < 1_000_000_000_000 ? $ts * 1000 : $ts;
        $nowMs = (int) round(microtime(true) * 1000);
        if (abs($nowMs - $tsMs) > $this->toleranceSeconds * 1000) {
            return false;
        }

        $provided = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        // The HMAC-SHA256 digest is always 64 lowercase-hex chars. Reject
        // anything else *before* comparing — a non-hex header (e.g. one that is
        // the same length but multi-byte) would otherwise make the two strings
        // differ in byte length and defeat the constant-time compare. Mirrors
        // the same guard in @b1-road/nestjs.
        if (preg_match('/^[0-9a-f]{64}$/', $provided) !== 1) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestampHeader.'.'.$rawBody, $this->secret);

        return hash_equals($expected, $provided);
    }
}
