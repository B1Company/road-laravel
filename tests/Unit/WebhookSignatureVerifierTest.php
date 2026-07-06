<?php

declare(strict_types=1);

use B1Road\Laravel\Webhooks\WebhookSignatureVerifier;

const WH_SECRET = 'whsec_test_secret';

function signWebhook(string $rawBody, string $timestamp, string $secret = WH_SECRET): string
{
    return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
}

it('accepts a correctly-signed delivery with the sha256= prefix', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);
    $body = '{"id":"evt_1","event":"organization.member.joined"}';
    $ts = (string) time();

    expect($verifier->verify($body, signWebhook($body, $ts), $ts))->toBeTrue();
});

it('also accepts the bare hex form (no prefix)', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);
    $body = '{"a":1}';
    $ts = (string) time();
    $bare = hash_hmac('sha256', $ts.'.'.$body, WH_SECRET);

    expect($verifier->verify($body, $bare, $ts))->toBeTrue();
});

it('rejects a tampered body', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);
    $ts = (string) time();
    $signature = signWebhook('{"amount":10}', $ts);

    expect($verifier->verify('{"amount":1000000}', $signature, $ts))->toBeFalse();
});

it('rejects a signature made with the wrong secret', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);
    $body = '{"a":1}';
    $ts = (string) time();

    expect($verifier->verify($body, signWebhook($body, $ts, 'whsec_wrong'), $ts))->toBeFalse();
});

it('rejects missing headers', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);

    expect($verifier->verify('{}', null, '123'))->toBeFalse();
    expect($verifier->verify('{}', 'sha256=abc', null))->toBeFalse();
    expect($verifier->verify('{}', 'sha256=abc', 'not-a-number'))->toBeFalse();
});

it('rejects a stale timestamp outside the tolerance window', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET, toleranceSeconds: 300);
    $body = '{"a":1}';
    $stale = (string) (time() - 1000);

    expect($verifier->verify($body, signWebhook($body, $stale), $stale))->toBeFalse();
});

it('accepts a millisecond timestamp as well as seconds', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET);
    $body = '{"a":1}';
    $tsMs = (string) (time() * 1000);

    // The signed string uses the literal header value, so sign with the ms form.
    expect($verifier->verify($body, signWebhook($body, $tsMs), $tsMs))->toBeTrue();
});

it('accepts the shared golden signature vector (cross-SDK contract)', function () {
    /** @var array<string,mixed> $vector */
    $vector = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/../contract-fixtures/webhook.signed-delivery.json'),
        associative: true,
    );

    // Wide tolerance — this vector pins the HMAC signature contract that the API
    // signer and every SDK verifier share, not the replay window (tested above).
    $verifier = new WebhookSignatureVerifier((string) $vector['secret'], toleranceSeconds: 10_000_000_000);

    expect($verifier->verify($vector['rawBody'], $vector['signatureHeader'], (string) $vector['timestamp']))->toBeTrue();
    // A single tampered byte in the signed body fails.
    expect($verifier->verify($vector['rawBody'].' ', $vector['signatureHeader'], (string) $vector['timestamp']))->toBeFalse();
});

it('reproduces the @b1-road/types WEBHOOK_SIGNING_VECTOR (C6 — same vector as nestjs + the API)', function () {
    // The exact const exported from @b1-road/types `webhook-signing.ts`. The
    // NestJS producer spec and every SDK verifier assert against THIS vector, so
    // pinning it here proves the Laravel verifier is in lockstep — a drift in the
    // signing scheme fails all of them at once rather than silently 401'ing real
    // deliveries. Kept in sync by hand (there is no TS→PHP import); if
    // @b1-road/types changes the vector, update these literals to match.
    $secret = 'whsec_road-signing-vector';
    $timestamp = '1700000000';
    $rawBody = '{"id":"whd_0000000000000000","event":"organization.member.joined","timestamp":"2023-11-14T22:13:20.000Z","data":{"businessUnitId":"bu_são_paulo","memberId":"mem_0000000000000000","userId":"usr_0000000000000000"}}';
    $signature = 'edd12b6dcb8b24d7c48f65c687f02ad0f173080eeaee4fac070919e8c22d4c6f';

    // The verifier recomputes the HMAC; matching the committed signature proves
    // the scheme (message = "{timestamp}.{rawBody}", HMAC-SHA256, lowercase hex).
    expect(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret))->toBe($signature);

    $verifier = new WebhookSignatureVerifier($secret, toleranceSeconds: 10_000_000_000);
    expect($verifier->verify($rawBody, 'sha256='.$signature, $timestamp))->toBeTrue();
    expect($verifier->verify($rawBody, $signature, $timestamp))->toBeTrue(); // bare hex too
});

it('rejects a non-hex signature before the constant-time compare (C6)', function () {
    $verifier = new WebhookSignatureVerifier(WH_SECRET, toleranceSeconds: 10_000_000_000);
    $body = '{"a":1}';
    $ts = (string) time();

    // 64 chars but not lowercase-hex (contains 'Z'/multi-byte) → rejected outright.
    expect($verifier->verify($body, 'sha256='.str_repeat('Z', 64), $ts))->toBeFalse();
    // Right length hex-ish but wrong content still fails (no accidental accept).
    expect($verifier->verify($body, 'sha256='.str_repeat('a', 63).'x', $ts))->toBeFalse();
});
