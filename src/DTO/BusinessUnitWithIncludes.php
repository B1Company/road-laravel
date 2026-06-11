<?php

declare(strict_types=1);

namespace B1Road\Laravel\DTO;

use Spatie\LaravelData\Data;

/**
 * A {@see BusinessUnitDetail} with eagerly-expanded children, returned by
 * `businessUnits()->get($id, include: ['members', 'roles'])`. Only the
 * requested relations are non-null. See SDK_DX_BAR principle #5 (expand
 * replaces N+1) — today the SDK fans out client-side; it collapses to a
 * single round-trip once the API ships native `include`.
 */
final class BusinessUnitWithIncludes extends Data
{
    /**
     * @param  list<Member>|null  $members
     * @param  list<Role>|null  $roles
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public string $status,
        public int $memberCount,
        public ?int $memberLimit,
        public ?string $joinCode,
        public string $createdAt,
        public string $iamScopeId,
        public ?array $members = null,
        public ?array $roles = null,
    ) {}

    public static function fromDetail(BusinessUnitDetail $detail, ?array $members, ?array $roles): self
    {
        return new self(
            id: $detail->id,
            name: $detail->name,
            slug: $detail->slug,
            status: $detail->status,
            memberCount: $detail->memberCount,
            memberLimit: $detail->memberLimit,
            joinCode: $detail->joinCode,
            createdAt: $detail->createdAt,
            iamScopeId: $detail->iamScopeId,
            members: $members,
            roles: $roles,
        );
    }
}
