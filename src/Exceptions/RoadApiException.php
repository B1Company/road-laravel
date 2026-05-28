<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * Catch-all for non-mapped HTTP statuses from the Road API.
 * Specific subclasses (Authn, Authz, NotFound, Conflict, Validation,
 * RateLimit, Network) are preferred when the status matches.
 */
final class RoadApiException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message,
        string $errorCode = 'api_error',
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
        return $this->status >= 400 && $this->status < 600 ? $this->status : 500;
    }
}
