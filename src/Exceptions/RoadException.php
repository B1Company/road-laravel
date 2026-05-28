<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use RuntimeException;
use Throwable;

abstract class RoadException extends RuntimeException
{
    /**
     * @param  string  $errorCode  Stable machine-readable code (e.g. `unauthenticated`).
     *                             We can't use the name `code` because the parent
     *                             RuntimeException already owns the integer `code` field.
     * @param  array<string,mixed>  $payload  The raw error body from the upstream
     *                                        (for caller-side inspection).
     */
    public function __construct(
        string $message,
        protected readonly string $errorCode,
        protected readonly ?string $requestId = null,
        protected readonly ?string $docsUrl = null,
        protected readonly array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function docsUrl(): ?string
    {
        return $this->docsUrl;
    }

    /** @return array<string,mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * The wire-shape returned to clients by HandleRoadExceptions middleware.
     *
     * @return array<string,mixed>
     */
    public function toErrorBody(): array
    {
        return array_filter([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'requestId' => $this->requestId,
            'docs' => $this->docsUrl,
        ], fn ($v) => $v !== null);
    }

    abstract public function httpStatus(): int;
}
