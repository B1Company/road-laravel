<?php

declare(strict_types=1);

namespace B1Road\Laravel\Attributes;

use Attribute;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Subject;

/**
 * Declarative permission requirement on a controller method or class.
 *
 *   class MembersController {
 *       #[RequirePermission(Action::Read, Subject::Member, in: 'buId')]
 *       public function index(string $buId) { ... }
 *   }
 *
 * The `in` argument is the route parameter name whose value is the
 * scope id (typically the Business Unit id). For request-input
 * sources, prefix with `input:` (e.g. `in: 'input:business_unit_id'`).
 *
 * Applied at the class level, the requirement covers every action on
 * the controller; method-level attributes override the class-level one.
 *
 * Enforcement runs via the `road.permission.attribute` middleware
 * (auto-added to the `road` middleware chain) — both the attribute and
 * the string-form `road.permission` middleware compile to the same
 * check.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RequirePermission
{
    public function __construct(
        public Action $action,
        public Subject $subject,
        public string $in,
    ) {
    }
}
