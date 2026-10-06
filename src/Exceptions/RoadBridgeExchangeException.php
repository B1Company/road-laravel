<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * The Bridge token exchange refused (`bridge()->exchangeForUser()`).
 *
 * `errorCode()` is the OAuth `error` Road answered with, one of the codes in
 * the guide's table (`BU_NOT_SUBSCRIBED_TO_PLATFORM`, `TOKEN_EXCHANGE_DENIED`,
 * …), and `$description` is its `error_description`: what happened and the
 * next step. Mirrors `RoadBridgeExchangeError` in `@b1-road/node-core`.
 */
final class RoadBridgeExchangeException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $errorCode,
        public readonly string $description,
        public readonly int $status,
        public readonly ?int $retryAfter = null,
        ?string $requestId = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('Bridge token exchange refused (%s): %s', $errorCode, $description),
            $errorCode,
            $requestId,
            null,
            $payload,
            $previous,
        );
    }

    public function httpStatus(): int
    {
        return $this->status >= 400 && $this->status < 600 ? $this->status : 502;
    }
}
