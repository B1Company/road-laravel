<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Result of `POST /iam/authorization/authorize/batch`. Wire shape of
 * `@b1-road/types/iam` `AuthorizeBatchResult`.
 */
final class AuthorizeBatchResult extends Data
{
    /** @param  DataCollection<int, AuthorizeBatchEntry>  $results */
    public function __construct(
        #[DataCollectionOf(AuthorizeBatchEntry::class)]
        public DataCollection $results,
    ) {}
}
