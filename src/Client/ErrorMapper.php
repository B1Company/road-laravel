<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Authorization\DecisionTrace;
use B1Road\Laravel\Exceptions\RoadApiException;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadConflictException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use B1Road\Laravel\Exceptions\RoadRateLimitException;
use B1Road\Laravel\Exceptions\RoadServerException;
use B1Road\Laravel\Exceptions\RoadValidationException;

/**
 * Maps an HTTP response from the Road API to a typed RoadException.
 *
 * The live API speaks RFC 7807 Problem Details (`{ type, title, status,
 * detail, instance, errors[], meta:{requestId} }`) — see the API's
 * `GlobalExceptionFilter`. We parse that first, then fall back to the legacy
 * `{ error: { code, message, ... } }` envelope the in-memory test fakes (and
 * older fixtures) still emit, so both shapes decode to the same typed error.
 *
 * Mirrors `mapStatusToError` in `apps/sdks/road-nestjs/src/errors.ts`.
 */
final class ErrorMapper
{
    /**
     * @param  array<string,mixed>|null  $body  Parsed JSON body, if any.
     * @param  array<string,mixed>|null  $headers  Response headers (used for `Retry-After`).
     */
    public static function map(int $status, ?array $body, ?array $headers = null): RoadException
    {
        $body ??= [];
        $legacy = is_array($body['error'] ?? null) ? $body['error'] : [];
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];

        // 7807 `detail`/`title` first, then the legacy nested message.
        $message = self::firstString([
            $body['detail'] ?? null,
            $body['title'] ?? null,
            $legacy['message'] ?? null,
        ]) ?? sprintf('Road API returned HTTP %d.', $status);

        // The 7807 body carries no stable machine code (only a kebab `type`
        // slug), so the canonical code is derived from the status — exactly
        // what @b1-road/nestjs does. An explicit `code` still wins if present.
        $code = self::firstString([
            $body['code'] ?? null,
            $legacy['code'] ?? null,
        ]) ?? self::defaultCodeForStatus($status);

        $requestId = self::firstString([
            $meta['requestId'] ?? null,
            $legacy['requestId'] ?? null,
            $body['requestId'] ?? null,
        ]);

        $docsUrl = self::firstString([
            $legacy['docs'] ?? null,
            $body['docs'] ?? null,
        ]);

        return match (true) {
            $status === 401 => new RoadAuthnException(
                message: $message,
                errorCode: $code,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 403 => new RoadAuthzException(
                message: $message,
                errorCode: $code,
                trace: self::extractTrace($body, $legacy),
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 404 => new RoadNotFoundException(
                message: $message,
                errorCode: $code,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 409 => new RoadConflictException(
                message: $message,
                errorCode: $code,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 422 || $status === 400 => new RoadValidationException(
                message: $message,
                errorCode: $code,
                fieldErrors: self::extractFieldErrors($body),
                status: $status,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 429 => new RoadRateLimitException(
                message: $message,
                errorCode: $code,
                retryAfter: self::retryAfterSeconds($headers),
                retryAfterMs: self::intOrNull($body['retryAfterMs'] ?? null),
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status >= 500 && $status < 600 => new RoadServerException(
                message: $message,
                errorCode: $code,
                status: $status,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            $status === 0 => new RoadNetworkException(
                message: $message !== '' ? $message : 'Network error talking to Road API.',
                errorCode: $code,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
            default => new RoadApiException(
                message: $message,
                errorCode: $code,
                status: $status,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $body,
            ),
        };
    }

    /**
     * The canonical stable code for an HTTP status when the body doesn't
     * carry an explicit one. Ported verbatim from `defaultCodeForStatus`
     * in `@b1-road/nestjs` so the two SDKs agree.
     */
    private static function defaultCodeForStatus(int $status): string
    {
        return match (true) {
            $status >= 500 => 'server_error',
            $status === 0 => 'network_error',
            $status === 401 => 'unauthenticated',
            $status === 403 => 'permission_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 || $status === 400 => 'validation_error',
            $status === 429 => 'rate_limited',
            $status >= 400 => 'client_error',
            default => 'unknown_error',
        };
    }

    /**
     * Build the field-error map from a 7807 `errors[]` array, keyed by field
     * name (`_root` when a row has no field), values are the messages.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,list<string>>
     */
    private static function extractFieldErrors(array $body): array
    {
        $errors = $body['errors'] ?? null;
        if (! is_array($errors)) {
            return [];
        }

        $out = [];
        foreach ($errors as $err) {
            if (! is_array($err)) {
                continue;
            }
            $field = self::firstString([$err['field'] ?? null]) ?? '_root';
            $out[$field][] = self::firstString([$err['message'] ?? null, $err['code'] ?? null]) ?? '';
        }

        return $out;
    }

    /**
     * Parse the DecisionTrace out of a 403 body. Road places it at top-level
     * `decision` (debug header on) or in the legacy `error.decision`/
     * `error.trace`; accept all three.
     *
     * @param  array<string,mixed>  $body
     * @param  array<string,mixed>  $legacy
     */
    private static function extractTrace(array $body, array $legacy): ?DecisionTrace
    {
        $candidate = $body['decision']
            ?? $legacy['decision']
            ?? $legacy['trace']
            ?? null;

        if (! is_array($candidate)) {
            return null;
        }

        return DecisionTrace::fromArray($candidate);
    }

    /** @param  array<string,mixed>|null  $headers */
    private static function retryAfterSeconds(?array $headers): ?int
    {
        if ($headers === null) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'retry-after') {
                return self::intOrNull(is_array($value) ? ($value[0] ?? null) : $value);
            }
        }

        return null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * First non-empty stringable candidate, or null.
     *
     * @param  list<mixed>  $candidates
     */
    private static function firstString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
            if (is_int($candidate) || is_float($candidate)) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
