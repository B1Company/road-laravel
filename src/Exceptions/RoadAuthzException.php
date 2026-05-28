<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use B1Road\Laravel\Authorization\DecisionTrace;
use Throwable;

/**
 * 403 — the calling subject lacks the required permissions. Carries
 * the structured `DecisionTrace` from Road so support tickets can
 * be diffed across SDKs.
 *
 * The exception's message includes the rendered trace (multi-line)
 * when one is present — Stripe-grade error readability.
 */
final class RoadAuthzException extends RoadException
{
    /** @param  array<string,mixed>  $payload */
    public function __construct(
        string $message = 'Permission denied.',
        string $errorCode = 'permission_denied',
        public readonly ?DecisionTrace $trace = null,
        ?string $requestId = null,
        ?string $docsUrl = null,
        array $payload = [],
        ?Throwable $previous = null,
    ) {
        $rendered = $trace !== null
            ? rtrim($message, '.').":\n".$trace->format()
            : $message;

        parent::__construct($rendered, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 403;
    }

    /** @return array<string,mixed> */
    public function toErrorBody(): array
    {
        $body = parent::toErrorBody();
        if ($this->trace !== null) {
            $body['decision'] = $this->trace->toArray();
        }

        return $body;
    }
}
