<?php

declare(strict_types=1);

namespace B1Road\Laravel\Extensions;

use DateTimeImmutable;

/**
 * A verified embed session context: what {@see ExtensionSessionVerifier}
 * returns once the signature, version, window and install all check out. The
 * `SessionContext` of `@b1-road/types/extensions`, flattened.
 */
final class ExtensionSession
{
    public function __construct(
        /** The person on the host page, as a **Road user id** (not the Auth Server user id). It is the id a data-leg or Bridge token carries as `onBehalfOfUser`. */
        public readonly string $userId,
        /** The install (`exti_…`): one extension in one business unit. Send it as `install` on `POST /extensions/token-exchange`. */
        public readonly string $installId,
        /** Your extension's public id (`ext_…`). */
        public readonly string $extensionId,
        /** The Road business unit id. Scope every record you keep for this person by it. */
        public readonly string $businessUnitId,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
