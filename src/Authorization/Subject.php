<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * The nouns of Road's permission algebra. Mirrors `RoadCoreSubject` in
 * `@b1-road/types`. The backed-enum string is exactly the wire form
 * (PascalCase) so `Subject::Member->value === 'Member'`.
 *
 * Custom platform-defined subjects are intentionally not modelled as
 * enum cases: the wire allows arbitrary strings, and integrators pass
 * them as strings (see `Can`). A subject here is what makes a check
 * Road's own; any other subject is checked in your platform's catalog.
 */
enum Subject: string
{
    case BusinessUnit = 'BusinessUnit';
    case BUDashboard = 'BUDashboard';
    case BUSettings = 'BUSettings';
    case Member = 'Member';
    case Role = 'Role';
    case Permission = 'Permission';
    case Invitation = 'Invitation';

    public static function fromConst(string $value): self
    {
        $normalized = trim($value);
        $subject = self::tryFrom($normalized);
        if ($subject === null) {
            throw new \InvalidArgumentException("Unknown Road subject: '$value'.");
        }

        return $subject;
    }
}
