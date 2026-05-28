<?php

declare(strict_types=1);

namespace B1Road\Laravel\Authorization;

/**
 * The verbs of Road's permission algebra. Mirrors `RoadAction` in
 * `@b1-road/types` — `create | read | update | delete | manage`. The
 * backed-enum string is exactly the wire form, so
 * `Action::Read->value` is the lower-case verb the API expects.
 */
enum Action: string
{
    case Create = 'create';
    case Read = 'read';
    case Update = 'update';
    case Delete = 'delete';
    case Manage = 'manage';

    public static function fromConst(string $value): self
    {
        $normalized = strtolower(trim($value));
        $action = self::tryFrom($normalized);
        if ($action === null) {
            throw new \InvalidArgumentException("Unknown Road action: '$value'.");
        }

        return $action;
    }
}
