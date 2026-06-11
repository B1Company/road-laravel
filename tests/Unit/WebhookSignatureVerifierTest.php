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
