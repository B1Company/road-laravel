<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/** One (permission, allowed) result inside an {@see AuthorizeBatchResult}. */
final class AuthorizeBatchEntry extends Data
{
    public function __construct(
        public string $permission,
        public bool $allowed,
    ) {}
}
