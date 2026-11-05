<?php

declare(strict_types=1);

namespace B1Road\Laravel\Webhooks\Payloads;

use Spatie\LaravelData\Data;

/**
 * Payload for `authz.assignment.{created,revoked}`: a role was assigned or
 * revoked at a scope of your platform's catalog. Drop what you cached for this
 * subject. Wire shape of `@b1-road/types` `AuthzAssignmentWebhookData`.
 */
final class AuthzAssignmentWebhookData extends Data
{
    public function __construct(
        /** The platform whose catalog the role belongs to (`plat_…`). */
        public string $platformPublicId,
        /** The assignment (`asg_…`). */
        public string $assignmentId,
        /** `user` or `service`. */
        public string $subjectType,
        public string $subjectId,
        /** The role (`role_…`). */
        public string $roleId,
        public string $scopeId,
    ) {}
}
