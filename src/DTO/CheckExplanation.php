<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * `GET /authz/checks/explain`: a decision with what it was made from. `chain`
 * is the scopes walked, the target first. `heldRoles` is what the subject holds
 * anywhere, not only on that chain.
 */
final class CheckExplanation extends Data
{
    /**
     * @param  list<string>  $chain
     * @param  list<Assignment>  $heldRoles
     */
    public function __construct(
        public string $code,
        public bool $allowed,
        public string $reason,
        public array $chain,
        public array $heldRoles,
    ) {}
}
