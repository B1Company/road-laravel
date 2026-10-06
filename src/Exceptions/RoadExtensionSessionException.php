<?php

declare(strict_types=1);

namespace B1Road\Laravel\Exceptions;

use B1Road\Laravel\Extensions\ExtensionSessionVerifier;

/**
 * {@see ExtensionSessionVerifier} refused an embed session context. Branch on
 * `errorCode()`, one of the constants below — the same codes as
 * `RoadExtensionSessionError` in the Node SDKs. Every one is the sender's
 * problem, so it renders as a 401 (the `road.errors` middleware does that for
 * you).
 */
final class RoadExtensionSessionException extends RoadException
{
    /** Not `{ payload, signature }` as Road issued them, or a signed payload missing a field. */
    public const MALFORMED = 'malformed';

    /** Altered on the way, or checked with a secret that is not the extension's current `signing` secret. */
    public const SIGNATURE_MISMATCH = 'signature_mismatch';

    /** A format version this SDK does not read (`v !== 1`). */
    public const UNSUPPORTED_VERSION = 'unsupported_version';

    /** Past `expiresAt` (or `maxAgeSeconds`), beyond 30 seconds of clock skew. */
    public const EXPIRED = 'expired';

    /** Issued more than 30 seconds ahead of this server's clock. */
    public const NOT_YET_VALID = 'not_yet_valid';

    /** For another install than the one expected. */
    public const INSTALL_MISMATCH = 'install_mismatch';

    public function __construct(string $errorCode, string $message)
    {
        parent::__construct($message, $errorCode);
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
