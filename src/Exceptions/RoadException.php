<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use RuntimeException;
use Throwable;

abstract class RoadException extends RuntimeException
{
    /**
     * Fallback base for the docs link an error carries when the API's own
     * problem body did not supply a `type`. The code's snake form is kebab-cased
     * into the slug (`permission_denied` → `permission-denied`), matching the
     * Road error catalog and the example in `standards/SDK_DX_BAR.md`.
     *
     * The previous value named the bare domain Road served before the
     * plat.eduzz.com cutover — and one the API never emitted anyway, since it
     * roots its `type` at the API origin rather than the bare domain. So the
     * fallback pointed somewhere that neither resolved nor matched the real
     * thing. See the CHANGELOG entry for the old value.
     *
     * ⚠️ Treat any of these as an identifier to compare, not a link to follow.
     * The origin differs per environment, and deployments today still emit a
     * retired one (B1-637). `errorCode` is the stable thing to branch on.
     */
    private const DOCS_BASE = 'https://api.plat.eduzz.com/errors';

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

    /**
     * Every Road error is self-documenting: when the upstream didn't supply
     * a `docs` link, derive the canonical one from the stable error code so
     * an integrator always has somewhere to go (SDK_DX_BAR principle #6).
     */
    public function docsUrl(): string
    {
        return $this->docsUrl ?? self::DOCS_BASE.'/'.str_replace('_', '-', $this->errorCode);
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
            'docs' => $this->docsUrl(),
        ], fn ($v) => $v !== null);
    }

    abstract public function httpStatus(): int;
}
