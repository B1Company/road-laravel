<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * `"$action:$Subject"` permission string utilities. Lowercase verb,
 * PascalCase subject: exactly what `POST /authz/checks` takes in `codes` and
 * what `RoadPermission` describes in `@b1-road/types`.
 */
final class Permission
{
    public const WILDCARD = '*';

    /**
     * Format an `action:Subject` permission string. Both arguments accept
     * either the canonical enum or a raw string, so platform-defined subjects
     * outside Road's core algebra (e.g. `'Project'`) format the same way they
     * do in `@b1-road/nestjs`. A string action is lower-cased to the wire verb;
     * a string subject is passed through (the wire form is PascalCase).
     */
    public static function format(Action|string $action, Subject|string $subject): string
    {
        $verb = $action instanceof Action ? $action->value : strtolower(trim($action));
        $noun = $subject instanceof Subject ? $subject->value : trim($subject);

        return $verb.':'.$noun;
    }

    /**
     * Whether a code belongs to Road's own catalog, going by its subject. Every
     * other subject is a platform's.
     */
    public static function isRoadCode(string $permission): bool
    {
        $colon = strpos($permission, ':');

        return $colon !== false && Subject::tryFrom(substr($permission, $colon + 1)) !== null;
    }

    public static function isWildcard(string $permission): bool
    {
        return trim($permission) === self::WILDCARD;
    }
}
