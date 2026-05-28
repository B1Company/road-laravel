<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

final class MyBusinessUnits extends Data
{
    /**
     * @param  DataCollection<int, Membership>  $memberships
     * @param  DataCollection<int, PendingInvitation>  $pendingInvitations
     */
    public function __construct(
        #[DataCollectionOf(Membership::class)]
        public DataCollection $memberships,
        #[DataCollectionOf(PendingInvitation::class)]
        public DataCollection $pendingInvitations,
    ) {}
}
