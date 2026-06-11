<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * 5xx — Road returned a server-side failure. This is the class the HTTP
 * transport retries with backoff (alongside {@see RoadNetworkException}),
 * since a 5xx is typically transient. Mirrors `RoadServerError` in
 * `@b1-road/nestjs`.
 */
final class RoadServerException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Road returned a server error.',
        string $errorCode = 'server_error',
        public readonly int $status = 500,
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return $this->status >= 500 && $this->status < 600 ? $this->status : 502;
    }
}
