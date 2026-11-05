<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * Common nouns of Road's permission algebra, for typing a code without a
 * string. Mirrors `RoadCoreSubject` in `@b1-road/types`. The backed-enum string
 * is exactly the wire form (PascalCase) so `Subject::Member->value === 'Member'`.
 *
 * A convenience, not a catalog: Road declares more subjects than these, and a
 * platform may declare codes on these same ones. The target of a check picks
 * the catalog that answers it (see `Can`), never the subject. Platform-defined
 * subjects are intentionally not modelled as enum cases: the wire allows
 * arbitrary strings, and integrators pass them as strings.
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
