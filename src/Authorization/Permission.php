<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * `"$action:$Subject"` permission string utilities. Lowercase verb,
 * PascalCase subject — exactly what `/iam/authorization/authorize`
 * expects on the wire and what `RoadPermission` describes in
 * `@b1-road/types`.
 */
final class Permission
{
    public const WILDCARD = '*';

    public static function format(Action $action, Subject $subject): string
    {
        return $action->value.':'.$subject->value;
    }

    public static function isWildcard(string $permission): bool
    {
        return trim($permission) === self::WILDCARD;
    }
}
