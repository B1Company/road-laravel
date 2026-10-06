<?php

declare(strict_types=1);

use B1Road\Laravel\Exceptions\RoadExtensionSessionException;
use B1Road\Laravel\Extensions\ExtensionSessionVerifier;

/**
 * The verifier against SESSION_CONTEXT_SIGNING_VECTOR, the triple the API's
 * signer and the Node verifier assert against too. There is no TS→PHP import,
 * so the literals are copied here and the first test proves they are still the
 * ones `@b1-road/types` publishes: a drift fails it instead of leaving this
 * suite green against a vector nobody else uses.
 */
const SC_SECRET = 'esk_test_vector_not_a_real_secret';
const SC_PAYLOAD = 'eyJ2IjoxLCJ1c2VyIjp7ImlkIjoiN2M5ZTY2NzktNzQyNS00MGRlLTk0NGItZTA3ZmMxZjkwYWU3In0sImluc3RhbGwiOnsiaWQiOiJleHRpX3ZlY3RvcjAwMDAwMDAwMDAwMCIsImV4dGVuc2lvbiI6ImV4dF92ZWN0b3IwMDAwMDAwMDAwMDAwIiwiYnVzaW5lc3NVbml0SWQiOiIzZGMzNmUzYy05NjY2LTQ5MmMtODkyZi03NzliODRiNzE2NTAifSwiaXNzdWVkQXQiOiIyMDI2LTAxLTAxVDAwOjAwOjAwLjAwMFoiLCJleHBpcmVzQXQiOiIyMDI2LTAxLTAxVDAwOjA1OjAwLjAwMFoifQ';
const SC_SIGNATURE = '068aa9ca03a7fe0a4adff7f1163d65d3ab2d43dc5b7242082ca3e55a7dcfe7fb';
const SC_INSTALL = 'exti_vector000000000000';

/** @return array<string, mixed> */
function vectorContext(): array
{
    return [
        'v' => 1,
        'user' => ['id' => '7c9e6679-7425-40de-944b-e07fc1f90ae7'],
        'install' => [
            'id' => SC_INSTALL,
            'extension' => 'ext_vector0000000000000',
            'businessUnitId' => '3dc36e3c-9666-492c-892f-779b84b71650',
        ],
        'issuedAt' => '2026-01-01T00:00:00.000Z',
        'expiresAt' => '2026-01-01T00:05:00.000Z',
    ];
}

/** Sign the way the API does: HMAC-SHA256 over the base64url JSON. */
function signContext(mixed $context, string $secret = SC_SECRET): array
{
    $payload = rtrim(strtr(base64_encode((string) json_encode($context, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

    return ['payload' => $payload, 'signature' => hash_hmac('sha256', $payload, $secret)];
}

/** A moment relative to the vector's issuedAt. */
function scAt(int $offsetSeconds): DateTimeImmutable
{
    return (new DateTimeImmutable('2026-01-01T00:00:00Z'))->modify(sprintf('%+d seconds', $offsetSeconds));
}

function refusalCode(callable $run): string
{
    try {
        $run();
    } catch (RoadExtensionSessionException $e) {
        expect($e->httpStatus())->toBe(401);

        return $e->errorCode();
    }
    throw new RuntimeException('expected the verifier to refuse');
}

$vector = ['payload' => SC_PAYLOAD, 'signature' => SC_SIGNATURE];

it('uses the vector @b1-road/types publishes', function () {
    $published = (string) file_get_contents(dirname(__DIR__, 2).'/../road-types/src/extensions.ts');

    foreach ([SC_SECRET, SC_PAYLOAD, SC_SIGNATURE, SC_INSTALL] as $literal) {
        expect($published)->toContain($literal);
    }
    // The payload is the encoding of exactly this context.
    expect(signContext(vectorContext()))->toBe(['payload' => SC_PAYLOAD, 'signature' => SC_SIGNATURE]);
});

it('accepts the shared vector and returns the context it carries', function () use ($vector) {
    $session = (new ExtensionSessionVerifier(SC_SECRET))->verify($vector, now: scAt(60));

    expect($session->userId)->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7')
        ->and($session->installId)->toBe(SC_INSTALL)
        ->and($session->extensionId)->toBe('ext_vector0000000000000')
        ->and($session->businessUnitId)->toBe('3dc36e3c-9666-492c-892f-779b84b71650')
        ->and($session->expiresAt->format('U'))->toBe((string) scAt(300)->format('U'));
});

it('reads the signed payload, never the decoded copy beside it', function () use ($vector) {
    $forged = $vector + ['context' => ['user' => ['id' => 'someone-else']], 'embedAssertion' => 'test-embed-assertion-not-a-jwt'];

    $session = (new ExtensionSessionVerifier(SC_SECRET))->verify($forged, now: scAt(60));

    expect($session->userId)->toBe('7c9e6679-7425-40de-944b-e07fc1f90ae7');
});

it('accepts the install it expects, and 30 seconds of clock skew at either end', function () use ($vector) {
    $verifier = new ExtensionSessionVerifier(SC_SECRET);

    expect($verifier->verify($vector, now: scAt(60), expectInstall: SC_INSTALL)->installId)->toBe(SC_INSTALL);
    expect($verifier->verify($vector, now: scAt(300 + 29))->installId)->toBe(SC_INSTALL);
    expect($verifier->verify($vector, now: scAt(-29))->installId)->toBe(SC_INSTALL);
});

it('refuses a tampered payload under the original signature', function () use ($vector) {
    $tampered = signContext(array_replace(vectorContext(), ['user' => ['id' => 'attacker']]))['payload'];

    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify(['payload' => $tampered] + $vector, now: scAt(60))))->toBe('signature_mismatch');
});

it('refuses a context checked with the wrong secret', function () use ($vector) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier('esk_some_other_secret'))
        ->verify($vector, now: scAt(60))))->toBe('signature_mismatch');
});

it('refuses an expired context, past 30 seconds of skew', function () use ($vector) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($vector, now: scAt(300 + 31))))->toBe('expired');
});

it('refuses a context older than maxAgeSeconds, though Road\'s own expiry is later', function () use ($vector) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($vector, now: scAt(120), maxAgeSeconds: 60)))->toBe('expired');
});

it('refuses a future-dated context, past 30 seconds of skew', function () use ($vector) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($vector, now: scAt(-31))))->toBe('not_yet_valid');
});

it('refuses a context for another install than the one expected', function () use ($vector) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($vector, now: scAt(60), expectInstall: 'exti_another00000000000')))->toBe('install_mismatch');
});

it('refuses a format version other than 1, even correctly signed', function () {
    $v2 = signContext(array_replace(vectorContext(), ['v' => 2]));

    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($v2, now: scAt(60))))->toBe('unsupported_version');
});

it('refuses input that is not { payload, signature } as Road issued them', function (array $input) {
    expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
        ->verify($input, now: scAt(60))))->toBe('malformed');
})->with([
    'empty' => [[]],
    'no signature' => [['payload' => SC_PAYLOAD]],
    'padded payload' => [['payload' => SC_PAYLOAD.'=', 'signature' => SC_SIGNATURE]],
    'prefixed signature' => [['payload' => SC_PAYLOAD, 'signature' => 'sha256=abc']],
    'non-hex signature' => [['payload' => SC_PAYLOAD, 'signature' => str_repeat('Z', 64)]],
]);

it('refuses a signed payload that is not JSON, or lacks a field', function () {
    $notJson = rtrim(strtr(base64_encode('not json'), '+/', '-_'), '=');
    $noUser = vectorContext();
    unset($noUser['user']);

    foreach ([
        ['payload' => $notJson, 'signature' => hash_hmac('sha256', $notJson, SC_SECRET)],
        signContext($noUser),
        signContext(array_replace(vectorContext(), ['expiresAt' => 'soon'])),
    ] as $input) {
        expect(refusalCode(fn () => (new ExtensionSessionVerifier(SC_SECRET))
            ->verify($input, now: scAt(60))))->toBe('malformed');
    }
});

it('refuses to be built with an empty secret', function () {
    // An empty HMAC key is one anyone can use, so this must never verify.
    expect(fn () => new ExtensionSessionVerifier(''))->toThrow(InvalidArgumentException::class);
});
