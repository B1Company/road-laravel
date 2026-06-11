<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * 409 — the request conflicts with current state (duplicate slug, version
 * skew, a member who already exists, …). Mirrors `RoadConflictError` in
 * `@b1-road/nestjs`.
 */
final class RoadConflictException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Conflict.',
        string $errorCode = 'conflict',
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
