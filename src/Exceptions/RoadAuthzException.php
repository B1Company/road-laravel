<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use B1Road\Laravel\Authorization\DecisionTrace;
use Throwable;

/**
 * 403 — the calling subject lacks the required permissions. Carries the
 * structured `DecisionTrace` from Road so support tickets can be diffed across
 * SDKs.
 *
 * The **message stays plain** ("Permission denied.") so it is safe to render on
 * the wire in any environment. The trace is exposed only via {@see decision()},
 * which the error renderer attaches to a 403 body only behind the debug trigger
 * in non-prod — a production 403 must never leak the caller's grants. Read the
 * rendered multi-line trace off `$e->trace->format()` in a log/breakpoint when
 * you want the human-readable form.
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
        parent::__construct($message, $errorCode, $requestId, $docsUrl, $payload, $previous);
    }

    public function httpStatus(): int
    {
        return 403;
    }

    /**
     * The structured decision as a wire array, or null. Exposed for the error
     * renderer to attach **only** when the debug trigger fires in a non-prod
     * environment — it is NOT in the default {@see toErrorBody()} so a
     * production 403 never leaks the caller's grants. Mirrors @b1-road/nestjs's
     * RoadDebugFilter, which gates the trace the same way.
     *
     * @return array<string,mixed>|null
     */
    public function decision(): ?array
    {
        return $this->trace?->toArray();
    }
}
