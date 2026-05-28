<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

final class BusinessUnitSummary extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
    ) {
    }
}
