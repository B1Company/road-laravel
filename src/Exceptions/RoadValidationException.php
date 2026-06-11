<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * 400 / 422 — the request payload failed validation. `fieldErrors` is keyed
 * by the request field name; each value is the list of validator messages
 * for that field (multiple per field), parsed from the RFC 7807 `errors[]`
 * array. Empty when the API returns a non-field-shaped error. Mirrors
 * `RoadValidationError` in `@b1-road/nestjs`.
 */
final class RoadValidationException extends RoadException
{
    /**
     * @param  array<string,list<string>>  $fieldErrors
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        string $message = 'Validation failed.',
        string $errorCode = 'validation_error',
        public readonly array $fieldErrors = [],
        public readonly int $status = 422,
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return $this->status === 400 ? 400 : 422;
    }

    /** @return array<string,mixed> */
    public function toErrorBody(): array
    {
        $body = parent::toErrorBody();
        if ($this->fieldErrors !== []) {
            $body['errors'] = $this->fieldErrors;
        }

        return $body;
    }
}
