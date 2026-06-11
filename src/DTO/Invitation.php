<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A business-unit invitation. Wire shape of `@b1-road/types` `Invitation`.
 * `acceptedVia` is `manual` / `auto` / null (null while pending).
 */
final class Invitation extends Data
{
    public function __construct(
        public string $id,
        public string $email,
        public string $roleId,
        public string $roleName,
        public string $status,
        public ?string $acceptedVia,
        public string $invitedAt,
        public string $expiresAt,
    ) {}
}
