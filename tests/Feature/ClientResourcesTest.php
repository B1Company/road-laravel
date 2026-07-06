<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\Assignment;
use B1Road\Laravel\DTO\AuthorizeResult;
use B1Road\Laravel\DTO\BusinessUnitWithIncludes;
use B1Road\Laravel\DTO\Invitation;
use B1Road\Laravel\DTO\Member;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\DTO\Scope;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

function seedRoadClient(): RoadClient
{
    /** @var RoadContext $ctx */
    $ctx = app(RoadContext::class);
    $ctx->setUser(new RoadUser(id: 'u_1', email: 'u1@example.com', name: 'User 1'));
    $ctx->setToken('test-access-token');
    $ctx->setRequestId('req_test_1');

    return app(RoadClient::class);
}

/** @return array<string,mixed> */
function buWire(string $id, string $scopeId): array
{
    return ['id' => $id, 'name' => 'BU '.$id, 'slug' => $id, 'status' => 'active', 'memberCount' => 1, 'memberLimit' => null, 'joinCode' => null, 'createdAt' => '2024-01-01T00:00:00Z', 'iamScopeId' => $scopeId];
}

/** @return array<string,mixed> */
function memberWire(string $id, string $userId): array
{
    return ['id' => $id, 'userId' => $userId, 'status' => 'active', 'joinedAt' => '2024-01-01T00:00:00Z', 'name' => 'User '.$userId, 'email' => $userId.'@test.local', 'roles' => [['id' => 'r_1', 'name' => 'Owner']]];
}

/** @return array<string,mixed> */
function apiRoleWire(string $id, string $name): array
{
    return ['id' => $id, 'name' => $name, 'description' => null, 'permissions' => ['read:Member'], 'isSystem' => false, 'assignmentCount' => 3];
}

/** @return array<string,mixed> */
function invitationWire(string $id, string $status = 'pending'): array
{
    return ['id' => $id, 'email' => 'new@test.local', 'roleId' => 'r_1', 'roleName' => 'Member', 'status' => $status, 'acceptedVia' => null, 'invitedAt' => '2024-01-01T00:00:00Z', 'expiresAt' => '2024-02-01T00:00:00Z'];
}

it('auto-paginates a members listing across cursors', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/members*' => Http::sequence()
            ->push(['data' => [memberWire('m1', 'u1')], 'pagination' => ['cursor' => 'c2', 'hasMore' => true, 'totalCount' => 2]])
            ->push(['data' => [memberWire('m2', 'u2')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 2]]),
    ]);

    $members = iterator_to_array(seedRoadClient()->businessUnits('bu_1')->members());

    expect($members)->toHaveCount(2);
    expect($members[0])->toBeInstanceOf(Member::class);
    expect($members[0]->id)->toBe('m1');
    expect($members[0]->roles[0]->name)->toBe('Owner');
    expect($members[1]->id)->toBe('m2');
    Http::assertSentCount(2); // followed the cursor onto page 2
});

it('exposes firstPage() as a bounded escape hatch', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/members*' => Http::response(
            ['data' => [memberWire('m1', 'u1')], 'pagination' => ['cursor' => 'next', 'hasMore' => true, 'totalCount' => 9]],
        ),
    ]);

    $page = seedRoadClient()->businessUnits('bu_1')->members()->firstPage(limit: 1);

    expect($page->data)->toHaveCount(1);
    expect($page->pagination->cursor)->toBe('next');
    expect($page->pagination->totalCount)->toBe(9);
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), 'limit=1'));
});

it('acts on a member through the collection', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    seedRoadClient()->businessUnits('bu_1')->members()->suspend('m1');

    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST'
        && str_ends_with($req->url(), '/organization/business-units/bu_1/members/m1/suspend'));
});

it('assigns a role to a member by role id', function () {
    Http::fake(['*' => Http::response(['message' => 'Role assigned'], 201)]);

    seedRoadClient()->businessUnits('bu_1')->members()->assignRole('m1', 'role_9');

    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST'
        && str_ends_with($req->url(), '/organization/business-units/bu_1/members/m1/roles')
        && ($req->data()['roleId'] ?? null) === 'role_9');
});

it('revokes a role from a member by role id', function () {
    Http::fake(['*' => Http::response(['message' => 'Role revoked'], 200)]);

    seedRoadClient()->businessUnits('bu_1')->members()->revokeRole('m1', 'role_9');

    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'DELETE'
        && str_ends_with($req->url(), '/organization/business-units/bu_1/members/m1/roles/role_9'));
});

it('resolves a BU to its IAM scope before listing roles, normalising null description', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => buWire('bu_1', 'scope_1')]),
        'api.road.test/api/alpha/iam/authorization/scopes/scope_1/roles*' => Http::response(
            ['data' => [apiRoleWire('r1', 'Owner')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]],
        ),
    ]);

    $roles = seedRoadClient()->businessUnits('bu_1')->roles()->all();

    expect($roles[0])->toBeInstanceOf(Role::class);
    expect($roles[0]->name)->toBe('Owner');
    expect($roles[0]->description)->toBe('');        // null → '' via RoleWire
    expect($roles[0]->assignmentCount)->toBe(3);
    // The BU detail is fetched once (memoised) even though we listed roles.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/iam/authorization/scopes/scope_1/roles'));
});

it('creates a role under a scope via the IAM navigator', function () {
    Http::fake([
        'api.road.test/api/alpha/iam/authorization/scopes/scope_1/roles' => Http::response(['data' => apiRoleWire('r_new', 'Editor')], 201),
    ]);

    $role = seedRoadClient()->iam()->scope('scope_1')->roles()->create(['name' => 'Editor', 'permissions' => ['read:Member']]);

    expect($role->id)->toBe('r_new');
    expect($role->name)->toBe('Editor');
    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST' && ($req->data()['name'] ?? null) === 'Editor');
});

it('lists, creates and cancels invitations on a BU', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/invitations/inv_1/cancel' => Http::response(['data' => invitationWire('inv_1', 'cancelled')]),
        'api.road.test/api/alpha/organization/business-units/bu_1/invitations' => Http::sequence()
            ->push(['data' => [invitationWire('inv_1')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]])
            ->push(['data' => invitationWire('inv_2')], 201),
    ]);

    $scope = seedRoadClient()->businessUnits('bu_1');

    $listed = $scope->invitations()->all();
    expect($listed[0])->toBeInstanceOf(Invitation::class);
    expect($listed[0]->status)->toBe('pending');

    $created = $scope->invitations()->create(['email' => 'new@test.local', 'roleId' => 'r_1']);
    expect($created->id)->toBe('inv_2');

    $cancelled = $scope->invitations()->cancel('inv_1');
    expect($cancelled->status)->toBe('cancelled');
});

it('forwards platformRoleIds on invitation create (C3)', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/invitations' => Http::response(
            ['data' => invitationWire('inv_9')],
            201,
        ),
    ]);

    seedRoadClient()->businessUnits('bu_1')->invitations()->create([
        'email' => 'new@test.local',
        'roleId' => 'r_1',
        'platformRoleIds' => ['pr_a', 'pr_b'],
    ]);

    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST'
        && $req->data()['platformRoleIds'] === ['pr_a', 'pr_b']);
});

it('accepts and rejects invitations by id at the top level', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/invitations/inv_1/accept' => Http::response(['data' => invitationWire('inv_1', 'accepted')]),
        'api.road.test/api/alpha/organization/invitations/inv_2/reject' => Http::response(['data' => invitationWire('inv_2', 'rejected')]),
    ]);

    $client = seedRoadClient();
    expect($client->invitations()->accept('inv_1')->status)->toBe('accepted');
    expect($client->invitations()->reject('inv_2')->status)->toBe('rejected');
});

it('runs IAM authorize and batch checks', function () {
    Http::fake([
        'api.road.test/api/alpha/iam/authorization/authorize' => Http::response(['data' => ['allowed' => true, 'reason' => 'granted']]),
        'api.road.test/api/alpha/iam/authorization/authorize/batch' => Http::response(['data' => ['results' => [
            ['permission' => 'read:Member', 'allowed' => true],
            ['permission' => 'delete:Member', 'allowed' => false],
        ]]]),
    ]);

    $iam = seedRoadClient()->iam();

    $single = $iam->authorize(['subjectType' => 'user', 'subjectId' => 'u_1', 'scopeId' => 'scope_1', 'permission' => 'read:Member']);
    expect($single)->toBeInstanceOf(AuthorizeResult::class);
    expect($single->allowed)->toBeTrue();
    expect($single->evaluatedScopes)->toBe([]); // live API omits it

    $batch = $iam->authorizeBatch(['subjectType' => 'user', 'subjectId' => 'u_1', 'scopeId' => 'scope_1', 'permissions' => ['read:Member', 'delete:Member']]);
    expect($batch->results)->toHaveCount(2);
    expect($batch->results[0]->allowed)->toBeTrue();
    expect($batch->results[1]->allowed)->toBeFalse();
});

it('manages scopes and assignments', function () {
    Http::fake([
        'api.road.test/api/alpha/iam/authorization/scopes/lookup*' => Http::response(['data' => ['id' => 'scope_x', 'type' => 'business_unit', 'externalId' => 'ext-1', 'parentScopeId' => null, 'metadata' => [], 'createdAt' => '2024-01-01T00:00:00Z']]),
        'api.road.test/api/alpha/iam/authorization/assignments' => Http::response(['data' => ['id' => 'as_1', 'subjectType' => 'user', 'subjectId' => 'u_1', 'roleId' => 'r_1', 'scopeId' => 'scope_1', 'grantedBy' => 'admin', 'grantedAt' => '2024-01-01T00:00:00Z', 'expiresAt' => null]], 201),
        'api.road.test/api/alpha/iam/authorization/subjects/user/u_1/assignments*' => Http::response(['data' => []]),
    ]);

    $iam = seedRoadClient()->iam();

    $scope = $iam->scopes()->lookup('business_unit', 'ext-1');
    expect($scope)->toBeInstanceOf(Scope::class);
    expect($scope->externalId)->toBe('ext-1');

    $assignment = $iam->assignments()->create(['subjectType' => 'user', 'subjectId' => 'u_1', 'roleId' => 'r_1', 'scopeId' => 'scope_1']);
    expect($assignment)->toBeInstanceOf(Assignment::class);
    expect($assignment->id)->toBe('as_1');

    expect($iam->assignments()->list('user', 'u_1'))->toBe([]);
});

it('derives a slug from the name on BU create', function () {
    Http::fake(['*' => Http::response(['data' => buWire('bu_new', 'scope_new')], 201)]);

    seedRoadClient()->businessUnits()->create(['name' => 'My New Team']);

    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST'
        && ($req->data()['slug'] ?? null) === 'my-new-team');
});

it('expands included relations in one get() call', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/members*' => Http::response(['data' => [memberWire('m1', 'u1')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]]),
        'api.road.test/api/alpha/iam/authorization/scopes/scope_1/roles*' => Http::response(['data' => [apiRoleWire('r1', 'Owner')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]]),
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => buWire('bu_1', 'scope_1')]),
    ]);

    $bu = seedRoadClient()->businessUnits()->get('bu_1', include: ['members', 'roles']);

    expect($bu)->toBeInstanceOf(BusinessUnitWithIncludes::class);
    expect($bu->members)->toHaveCount(1);
    expect($bu->members[0]->id)->toBe('m1');
    expect($bu->roles)->toHaveCount(1);
    expect($bu->roles[0]->name)->toBe('Owner');
});

it('rejects an unknown include key', function () {
    expect(fn () => seedRoadClient()->businessUnits()->get('bu_1', include: ['nope']))
        ->toThrow(InvalidArgumentException::class);
});
