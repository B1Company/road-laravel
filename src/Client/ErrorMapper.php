<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client;

use B1Road\Laravel\Authorization\DecisionTrace;
use B1Road\Laravel\Exceptions\RoadApiException;
use B1Road\Laravel\Exceptions\RoadAuthnException;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadException;
use B1Road\Laravel\Exceptions\RoadNetworkException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;

/**
 * Maps an HTTP response from the Road API to a typed RoadException.
 * MVP covers 401 / 404 / network / catch-all; the full taxonomy
 * (403, 409, 422, 429) lands in Day 7 / follow-ups.
 *
 * Mirrors `apps/sdks/road-nestjs/src/errors.ts` mapStatusToError.
 */
final class ErrorMapper
{
    /**
     * @param  array<string,mixed>|null  $body  Parsed JSON body, if any.
     * @param  array<string,mixed>|null  $headers
     */
    public static function map(int $status, ?array $body, ?array $headers = null): RoadException
    {
        $envelope = is_array($body['error'] ?? null) ? $body['error'] : ($body ?? []);
        $message = (string) ($envelope['message'] ?? sprintf('Road API returned HTTP %d.', $status));
        $code = isset($envelope['code']) ? (string) $envelope['code'] : null;
        $requestId = isset($envelope['requestId']) ? (string) $envelope['requestId'] : null;
        $docsUrl = isset($envelope['docs']) ? (string) $envelope['docs'] : null;
        $payload = $body ?? [];

        return match (true) {
            $status === 401 => new RoadAuthnException(
                message: $message,
                errorCode: $code ?? 'unauthenticated',
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $payload,
            ),
            $status === 403 => new RoadAuthzException(
                message: $message,
                errorCode: $code ?? 'permission_denied',
                trace: self::extractTrace($envelope, $body),
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $payload,
            ),
            $status === 404 => new RoadNotFoundException(
                message: $message,
                errorCode: $code ?? 'not_found',
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $payload,
            ),
            $status === 0 => new RoadNetworkException(
                message: $message !== '' ? $message : 'Network error talking to Road API.',
                errorCode: $code ?? 'network_error',
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $payload,
            ),
            default => new RoadApiException(
                message: $message,
                errorCode: $code ?? 'api_error',
                status: $status,
                requestId: $requestId,
                docsUrl: $docsUrl,
                payload: $payload,
            ),
        };
    }

    /**
     * Parse the DecisionTrace out of a 403 envelope. Road's wire format
     * places it at either `error.decision` (debug header on) or
     * top-level `decision` (some fixtures); accept both.
     *
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>|null  $body
     */
    private static function extractTrace(array $envelope, ?array $body): ?DecisionTrace
    {
        $candidate = $envelope['decision']
            ?? $envelope['trace']
            ?? ($body['decision'] ?? null);

        if (! is_array($candidate)) {
            return null;
        }

        return DecisionTrace::fromArray($candidate);
    }
}
