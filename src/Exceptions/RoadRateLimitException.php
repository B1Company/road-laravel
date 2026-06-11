<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use Throwable;

/**
 * 429 — the caller is rate-limited. `retryAfter` (seconds) is parsed from
 * the `Retry-After` response header; `retryAfterMs` is preserved when Road's
 * body carries a millisecond override. The transport never auto-retries a
 * 429 — it surfaces the wait so the caller can back off deliberately.
 * Mirrors `RoadRateLimitError` in `@b1-road/nestjs`.
 */
final class RoadRateLimitException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Rate limit exceeded.',
        string $errorCode = 'rate_limited',
        public readonly ?int $retryAfter = null,
        public readonly ?int $retryAfterMs = null,
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 429;
    }

    /** @return array<string,mixed> */
    public function toErrorBody(): array
    {
        $body = parent::toErrorBody();
        if ($this->retryAfter !== null) {
            $body['retryAfter'] = $this->retryAfter;
        }

        return $body;
    }
}
