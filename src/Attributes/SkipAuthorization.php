<?php

declare(strict_types=1);

namespace B1Road\Laravel\Attributes;

use Attribute;

/**
 * Opt out of attribute-driven authorization on a single method
 * (overrides a class-level `#[RequirePermission]` for that method).
 *
 *   #[RequirePermission(Action::Manage, Subject::Member, in: 'buId')]
 *   class MembersController {
 *       #[SkipAuthorization]
 *       public function publicHealthCheck() { ... }
 *   }
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class SkipAuthorization
{
}
