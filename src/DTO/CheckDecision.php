<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * One code's verdict from `POST /authz/checks`. `reason` is the engine's word
 * for it: `allowed_role`, `denied_no_grant`, `denied_unknown_code` (the catalog
 * does not declare the code, which is a developer error), and so on.
 */
final class CheckDecision extends Data
{
    public function __construct(
        public string $code,
        public bool $allowed,
        public string $reason,
    ) {}
}
