<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Assignment;

/**
 * Assignments, by scope or by subject. Iterable (auto-paginating) with a
 * `firstPage()` escape hatch.
 *
 * @extends Paginates<Assignment>
 */
final class AssignmentCollection extends Paginates
{
    public function __construct(HttpTransportInterface $http, private readonly string $path)
    {
        parent::__construct($http);
    }

    protected function listPath(): string
    {
        return $this->path;
    }

    protected function mapRow(array $row): Assignment
    {
        return Assignment::from($row);
    }
}
