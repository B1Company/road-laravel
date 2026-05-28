<?php

declare(strict_types=1);

namespace B1Road\Laravel\Testing;

/**
 * Fluent test-fixture builder. Mirrors `apps/sdks/road-nestjs/src/testing/scenario.ts`
 * — same shape so tests can be ported between SDKs by transliteration.
 *
 * MVP surface: `withUser`, `withBusinessUnit`, `withMember`. Role,
 * invitation, and authorize fixtures arrive with the full client
 * surface follow-up.
 */
final class RoadScenario
{
    /** @var array<string, array{id:string, name:string, email:string, avatarUrl?:string}> */
    public array $users = [];

    /** @var array<string, array{id:string, name:string, slug:string, status:string, memberCount:int, memberLimit:?int, joinCode:?string, createdAt:string, iamScopeId:string}> */
    public array $businessUnits = [];

    /** @var array<string, array{userId:string, memberships:list<array{businessUnit:array{id:string,name:string,slug:string}, status:string, joinedAt:string, roles:list<array{id:string,name:string}>}>}> */
    public array $userBusinessUnits = [];

    public static function make(): self
    {
        return new self();
    }

    public function withUser(
        string $id,
        ?string $name = null,
        ?string $email = null,
        ?string $avatarUrl = null,
    ): self {
        $this->users[$id] = array_filter([
            'id'        => $id,
            'name'      => $name ?? $id,
            'email'     => $email ?? $id.'@test.local',
            'avatarUrl' => $avatarUrl,
        ], fn ($v) => $v !== null);

        // Initialize empty memberships container.
        $this->userBusinessUnits[$id] ??= ['userId' => $id, 'memberships' => []];

        return $this;
    }

    public function withBusinessUnit(
        string $id,
        ?string $name = null,
        ?string $slug = null,
        ?string $iamScopeId = null,
    ): self {
        $this->businessUnits[$id] = [
            'id'          => $id,
            'name'        => $name ?? $id,
            'slug'        => $slug ?? str_replace('_', '-', $id),
            'status'      => 'active',
            'memberCount' => 0,
            'memberLimit' => null,
            'joinCode'    => null,
            'createdAt'   => '2024-01-01T00:00:00Z',
            'iamScopeId'  => $iamScopeId ?? 'scope_'.$id,
        ];

        return $this;
    }

    /**
     * @param  list<string>  $roles  Role names this member has on the BU.
     */
    public function withMember(string $buId, string $userId, array $roles = []): self
    {
        if (! isset($this->businessUnits[$buId])) {
            throw new \InvalidArgumentException("Business unit $buId not declared. Call withBusinessUnit() first.");
        }
        if (! isset($this->users[$userId])) {
            throw new \InvalidArgumentException("User $userId not declared. Call withUser() first.");
        }

        $bu = $this->businessUnits[$buId];
        $this->userBusinessUnits[$userId] ??= ['userId' => $userId, 'memberships' => []];
        $this->userBusinessUnits[$userId]['memberships'][] = [
            'businessUnit' => ['id' => $bu['id'], 'name' => $bu['name'], 'slug' => $bu['slug']],
            'status'       => 'active',
            'joinedAt'     => '2024-01-01T00:00:00Z',
            'roles'        => array_map(
                fn (string $r): array => ['id' => 'r_'.$r, 'name' => $r],
                $roles,
            ),
        ];

        $this->businessUnits[$buId]['memberCount']++;

        return $this;
    }

    /**
     * Issue a "Bearer fake-{userId}" header tuple for $this->withHeaders(...).
     *
     * @return array<string,string>
     */
    public function authAs(string $userId): array
    {
        if (! isset($this->users[$userId])) {
            throw new \InvalidArgumentException("User $userId not declared in scenario.");
        }

        return ['Authorization' => 'Bearer fake-'.$userId];
    }

    /** @return array{id:string, name:string, email:string, avatarUrl?:string}|null */
    public function findUser(string $id): ?array
    {
        return $this->users[$id] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function findBusinessUnit(string $id): ?array
    {
        return $this->businessUnits[$id] ?? null;
    }

    /** @return array{userId:string, memberships:list<array<string,mixed>>} */
    public function memberships(string $userId): array
    {
        return $this->userBusinessUnits[$userId] ?? ['userId' => $userId, 'memberships' => []];
    }

}
