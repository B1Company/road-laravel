<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class Membership extends Data
{
    /**
     * @param  DataCollection<int, RoleRef>  $roles
     */
    public function __construct(
        public BusinessUnitSummary $businessUnit,
        public string $status,
        public string $joinedAt,
        #[DataCollectionOf(RoleRef::class)]
        public DataCollection $roles,
    ) {
    }
}
