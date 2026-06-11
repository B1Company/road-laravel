<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * A member of a business unit. Wire shape of `@b1-road/types` `Member`.
 */
final class Member extends Data
{
    /**
     * @param  DataCollection<int, RoleRef>  $roles
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $status,
        public string $joinedAt,
        public string $name,
        public string $email,
        #[DataCollectionOf(RoleRef::class)]
        public DataCollection $roles,
    ) {}
}
