<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * Wire-shape result from `POST /iam/authorization/authorize`.
 * `evaluatedScopes` lists the scope chain Road walked during evaluation.
 */
final class AuthorizeResult extends Data
{
    /** @param  list<string>  $evaluatedScopes */
    public function __construct(
        public bool $allowed,
        public string $reason,
        public array $evaluatedScopes,
    ) {
    }
}
