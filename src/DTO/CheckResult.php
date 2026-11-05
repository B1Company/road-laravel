<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/** `POST /authz/checks`: one decision per code, in the order they were sent. */
final class CheckResult extends Data
{
    /** @param  list<CheckDecision>  $results */
    public function __construct(
        public array $results,
    ) {}
}
