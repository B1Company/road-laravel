<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

final class BusinessUnitDetail extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public int $memberCount,
        public ?int $memberLimit,
        public ?string $joinCode,
        public string $createdAt,
        public string $iamScopeId,
    ) {
    }
}
