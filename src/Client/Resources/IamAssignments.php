<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Assignment;

/**
 * Read and revoke role assignments by id or subject. Reached via
 * `Road::client()->iam()->assignments()`. To assign, go through the scope:
 * `iam()->scope($scopeId)->assign([...])`.
 */
final class IamAssignments
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /** Everything a subject holds, at any scope. */
    public function list(string $subjectType, string $subjectId): AssignmentCollection
    {
        return new AssignmentCollection(
            $this->http,
            '/authz/subjects/'.rawurlencode($subjectType).'/'.rawurlencode($subjectId).'/assignments',
        );
    }

    public function get(string $assignmentId): Assignment
    {
        return Assignment::from($this->unwrap(
            $this->http->request('GET', '/authz/assignments/'.rawurlencode($assignmentId)),
        ));
    }

    public function delete(string $assignmentId): void
    {
        $this->http->request('DELETE', '/authz/assignments/'.rawurlencode($assignmentId));
    }
}
