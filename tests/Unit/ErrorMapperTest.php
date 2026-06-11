<?php

declare(strict_types=1);

use B1Road\Laravel\Client\ErrorMapper;
use B1Road\Laravel\Exceptions\RoadApiException;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadConflictException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use B1Road\Laravel\Exceptions\RoadRateLimitException;
use B1Road\Laravel\Exceptions\RoadServerException;
use B1Road\Laravel\Exceptions\RoadValidationException;

/** A realistic RFC 7807 body as the live GlobalExceptionFilter emits it. */
function problem(int $status, string $type, string $title, string $detail, array $extra = []): array
{
    return array_merge([
        'type' => "https://api.road.b1.app/errors/$type",
        'title' => $title,
        'status' => $status,
        'detail' => $detail,
        'instance' => '/api/alpha/organization/business-units/bu_1',
        'meta' => ['requestId' => 'req_7807', 'timestamp' => '2026-06-11T00:00:00Z'],
    ], $extra);
}

it('maps each status to its typed exception with a status-derived code', function (int $status, string $class, string $code) {
    $error = ErrorMapper::map($status, problem($status, 'x', 'X', 'boom'));

    expect($error)->toBeInstanceOf($class);
    expect($error->errorCode())->toBe($code);
    expect($error->getMessage())->toContain('boom');
    expect($error->httpStatus())->toBe($status === 0 ? 502 : $status);
})->with([
    'auth' => [401, RoadAuthnException::class, 'unauthenticated'],
    'forbidden' => [403, RoadAuthzException::class, 'permission_denied'],
    'not found' => [404, RoadNotFoundException::class, 'not_found'],
    'conflict' => [409, RoadConflictException::class, 'conflict'],
    'validation' => [422, RoadValidationException::class, 'validation_error'],
    'server' => [503, RoadServerException::class, 'server_error'],
]);

it('derives requestId from 7807 meta', function () {
    $error = ErrorMapper::map(404, problem(404, 'not-found', 'Not Found', 'gone'));

    expect($error->requestId())->toBe('req_7807');
});

it('falls back to the legacy {error:{code,message}} envelope the fakes emit', function () {
    $error = ErrorMapper::map(404, ['error' => [
        'code' => 'not_found',
        'message' => 'No such BU',
        'requestId' => 'req_legacy',
        'docs' => 'https://road.b1.app/errors/custom',
    ]]);

    expect($error)->toBeInstanceOf(RoadNotFoundException::class);
    expect($error->getMessage())->toBe('No such BU');
    expect($error->requestId())->toBe('req_legacy');
    expect($error->docsUrl())->toBe('https://road.b1.app/errors/custom');
});

it('self-documents with a kebab-cased docs URL when none is supplied', function () {
    $error = ErrorMapper::map(403, problem(403, 'permission-denied', 'Permission Denied', 'nope'));

    expect($error->docsUrl())->toBe('https://road.b1.app/errors/permission-denied');
});

it('builds fieldErrors from the 7807 errors[] array on a validation error', function () {
    $error = ErrorMapper::map(422, problem(422, 'unprocessable-entity', 'Unprocessable Entity', 'invalid', [
        'errors' => [
            ['field' => 'email', 'code' => 'INVALID_FORMAT', 'message' => 'email must be an email'],
            ['field' => 'email', 'code' => 'INVALID_FORMAT', 'message' => 'email is required'],
            ['field' => 'name', 'code' => 'INVALID_FORMAT', 'message' => 'name should not be empty'],
        ],
    ]));

    expect($error)->toBeInstanceOf(RoadValidationException::class);
    /** @var RoadValidationException $error */
    expect($error->fieldErrors)->toBe([
        'email' => ['email must be an email', 'email is required'],
        'name' => ['name should not be empty'],
    ]);
    expect($error->toErrorBody()['errors'])->toHaveKey('email');
});

it('maps a 400 to a validation error too (class-validator returns 400)', function () {
    $error = ErrorMapper::map(400, problem(400, 'validation-error', 'Validation Error', 'bad input'));

    expect($error)->toBeInstanceOf(RoadValidationException::class);
    /** @var RoadValidationException $error */
    expect($error->status)->toBe(400);
    expect($error->httpStatus())->toBe(400);
});

it('parses Retry-After (seconds) from the response headers on a 429', function () {
    $error = ErrorMapper::map(
        429,
        problem(429, 'rate-limited', 'Rate Limit Exceeded', 'slow down', ['retryAfterMs' => 1500]),
        ['Retry-After' => '12'],
    );

    expect($error)->toBeInstanceOf(RoadRateLimitException::class);
    /** @var RoadRateLimitException $error */
    expect($error->retryAfter)->toBe(12);
    expect($error->retryAfterMs)->toBe(1500);
    expect($error->toErrorBody()['retryAfter'])->toBe(12);
});

it('extracts the DecisionTrace from a 403 body', function () {
    $error = ErrorMapper::map(403, problem(403, 'permission-denied', 'Permission Denied', 'denied', [
        'decision' => [
            'subject' => 'user_42',
            'scope' => 'bu_1',
            'required' => ['read:Member'],
            'grants' => [],
            'verdict' => 'deny',
            'reason' => 'No grant covers read:Member',
        ],
    ]));

    expect($error)->toBeInstanceOf(RoadAuthzException::class);
    /** @var RoadAuthzException $error */
    expect($error->trace)->not->toBeNull();
    expect($error->trace->verdict)->toBe('deny');
    expect($error->getMessage())->toContain('read:Member');
});

it('maps status 0 to a network error and an unmapped status to the catch-all', function () {
    expect(ErrorMapper::map(0, null))->toBeInstanceOf(RoadNetworkException::class);

    $teapot = ErrorMapper::map(418, ['detail' => "I'm a teapot"]);
    expect($teapot)->toBeInstanceOf(RoadApiException::class);
    expect($teapot->errorCode())->toBe('client_error');
    expect($teapot->httpStatus())->toBe(418);
});
