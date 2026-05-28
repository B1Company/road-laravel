<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

final class RoadNotFoundException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Resource not found.',
        string $errorCode = 'not_found',
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
