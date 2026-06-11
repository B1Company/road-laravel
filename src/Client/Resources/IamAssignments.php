<?php

declare(strict_types=1);

namespace B1Road\Laravel\Client\Resources;

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\DTO\Assignment;

/**
 * Create / list / delete role assignments. Reached via
 * `Road::client()->iam()->assignments()`. Mirrors the `iam.assignments`
 * object in road-nestjs.
 */
final class IamAssignments
{
    use UnwrapsData;

    public function __construct(private readonly HttpTransportInterface $http) {}

    /** @param  array<string,mixed>  $input */
    public function create(array $input): Assignment
    {
        $body = $this->http->request('POST', '/iam/authorization/assignments', $input);

        return Assignment::from($this->unwrap($body));
    }

    /** @return list<Assignment> */
    public function list(string $subjectType, string $subjectId, ?string $scopeId = null): array
    {
        $body = $this->http->request(
            'GET',
            '/iam/authorization/subjects/'.rawurlencode($subjectType).'/'.rawurlencode($subjectId).'/assignments',
            null,
            $scopeId !== null ? ['scopeId' => $scopeId] : [],
        );

        $rows = $this->unwrap($body);
        $out = [];
        foreach (array_values($rows) as $row) {
            if (is_array($row)) {
                $out[] = Assignment::from($row);
            }
        }

        return $out;
    }

    public function delete(string $assignmentId): void
    {
        $this->http->request('DELETE', '/iam/authorization/assignments/'.rawurlencode($assignmentId));
    }
}
