<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

final class PendingInvitation extends Data
{
    public function __construct(
        public string $id,
        public BusinessUnitSummary $businessUnit,
        public string $roleName,
        public string $expiresAt,
    ) {}
}
