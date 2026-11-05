<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\Assignment;
use B1Road\Laravel\DTO\BusinessUnitWithIncludes;
use B1Road\Laravel\DTO\Invitation;
use B1Road\Laravel\DTO\Member;
use B1Road\Laravel\DTO\PlatformSubscriptionResolution;
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
    return ['id' => $id, 'name' => $name, 'description' => null, 'audience' => 'customer', 'grantsAll' => false, 'custom' => true, 'scopeId' => 'scope_1', 'permissionCount' => 1, 'assignmentCount' => 3, 'createdAt' => '2024-01-01T00:00:00Z', 'updatedAt' => '2024-01-01T00:00:00Z'];
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

it('resolves a platform subscription by public id (C4)', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1/subscriptions/plat_gw' => Http::response([
            'data' => [
                'subscriptionId' => 'sub_1',
                'platformId' => 'plat_gw',
                'slug' => 'payment-gateway',
                'scopeId' => 'scope_plat_1',
            ],
        ], 200),
    ]);

    $sub = seedRoadClient()->businessUnits('bu_1')->subscriptions('plat_gw');

    expect($sub)->toBeInstanceOf(PlatformSubscriptionResolution::class);
    expect($sub->subscriptionId)->toBe('sub_1');
    expect($sub->scopeId)->toBe('scope_plat_1');
    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'GET'
        && str_ends_with($req->url(), '/organization/business-units/bu_1/subscriptions/plat_gw'));
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

it('resolves a BU to its scope before listing roles', function () {
    Http::fake([
        'api.road.test/api/alpha/organization/business-units/bu_1' => Http::response(['data' => buWire('bu_1', 'scope_1')]),
        'api.road.test/api/alpha/authz/scopes/scope_1/roles*' => Http::response(
            ['data' => [apiRoleWire('r1', 'Owner')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]],
        ),
    ]);

    $roles = seedRoadClient()->businessUnits('bu_1')->roles()->all();

    expect($roles[0])->toBeInstanceOf(Role::class);
    expect($roles[0]->name)->toBe('Owner');
    expect($roles[0]->description)->toBeNull();
    expect($roles[0]->custom)->toBeTrue();
    expect($roles[0]->assignmentCount)->toBe(3);
    // The BU detail is fetched once (memoised) even though we listed roles.
    Http::assertSent(fn (HttpRequest $req) => str_contains($req->url(), '/authz/scopes/scope_1/roles'));
});

it('creates a role under a scope via the IAM navigator', function () {
    Http::fake([
        'api.road.test/api/alpha/authz/scopes/scope_1/roles' => Http::response(['data' => apiRoleWire('r_new', 'Editor')], 201),
    ]);

    $role = seedRoadClient()->iam()->scope('scope_1')->roles()->create(['name' => 'Editor', 'permissionIds' => ['perm_1']]);

    expect($role->id)->toBe('r_new');
    expect($role->name)->toBe('Editor');
    Http::assertSent(fn (HttpRequest $req) => $req->method() === 'POST'
        && str_ends_with($req->url(), '/api/alpha/authz/scopes/scope_1/roles')
        && $req->data() === ['name' => 'Editor', 'permissionIds' => ['perm_1']]);
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
    expect($client->invitations()->accept('inv_1', 'link-token')->status)->toBe('accepted');
    expect($client->invitations()->reject('inv_2')->status)->toBe('rejected');
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
        'api.road.test/api/alpha/authz/scopes/scope_1/roles*' => Http::response(['data' => [apiRoleWire('r1', 'Owner')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]]),
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

function assignmentWire(string $id): array
{
    return ['id' => $id, 'subjectType' => 'user', 'subjectId' => 'u_1', 'roleId' => 'role_1', 'roleName' => 'Owner', 'scopeId' => 'scope_1', 'grantedBy' => 'u_0', 'grantedAt' => '2024-01-01T00:00:00Z', 'expiresAt' => null];
}

it('addresses one role by its own id, with no scope lookup', function () {
    Http::fake([
        'api.road.test/api/alpha/authz/roles/role_9' => Http::sequence()
            ->push(['data' => apiRoleWire('role_9', 'Editor')])
            ->push(['data' => apiRoleWire('role_9', 'Renamed')])
            ->push(['data' => ['assignmentsRevoked' => 4]]),
    ]);
    Http::preventStrayRequests();

    $roles = seedRoadClient()->businessUnits('bu_1')->roles();

    expect($roles->get('role_9')->name)->toBe('Editor');
    expect($roles->update('role_9', ['name' => 'Renamed'])->name)->toBe('Renamed');
    expect($roles->delete('role_9'))->toBe(4);
    Http::assertSentCount(3);
});

it('reads and edits what a role composes', function () {
    $permission = ['id' => 'perm_1', 'code' => 'read:Invoice', 'name' => 'Read', 'description' => null, 'category' => 'Billing', 'audience' => 'customer', 'scopeId' => 'scope_m', 'createdAt' => '2024-01-01T00:00:00Z', 'updatedAt' => '2024-01-01T00:00:00Z'];
    Http::fake([
        'api.road.test/api/alpha/authz/roles/role_9/permissions*' => Http::sequence()
            ->push(['data' => [$permission], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 1]])
            ->push(['data' => apiRoleWire('role_9', 'Editor')])
            ->push(['data' => apiRoleWire('role_9', 'Editor')]),
    ]);
    Http::preventStrayRequests();

    $roles = seedRoadClient()->iam()->scope('scope_1')->roles();

    expect($roles->permissions('role_9')->all()[0]->code)->toBe('read:Invoice');
    expect($roles->compose('role_9', 'perm_1')->id)->toBe('role_9');
    expect($roles->decompose('role_9', 'perm_1')->id)->toBe('role_9');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->data() === ['permissionId' => 'perm_1']);
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/authz/roles/role_9/permissions/perm_1'));
});

it('walks the permissions available at a scope, page by page', function () {
    $row = fn (string $id) => ['id' => $id, 'code' => 'read:X', 'name' => 'X', 'description' => null, 'category' => null, 'audience' => 'customer', 'scopeId' => 'scope_1', 'createdAt' => '', 'updatedAt' => ''];
    Http::fake(['api.road.test/api/alpha/authz/scopes/scope_1/permissions*' => Http::sequence()
        ->push(['data' => [$row('perm_1')], 'pagination' => ['cursor' => 'c2', 'hasMore' => true, 'totalCount' => 2]])
        ->push(['data' => [$row('perm_2')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 2]])]);
    Http::preventStrayRequests();

    $ids = array_map(fn ($p) => $p->id, seedRoadClient()->iam()->scope('scope_1')->permissions()->all());

    expect($ids)->toBe(['perm_1', 'perm_2']);
});

it('looks up and reads a scope', function () {
    $wire = ['id' => 'scope_x', 'type' => 'business_unit', 'externalId' => 'ext-1', 'parentScopeId' => null, 'catalogScopeId' => 'scope_road', 'createdAt' => '2024-01-01T00:00:00Z'];
    Http::fake([
        'api.road.test/api/alpha/authz/scopes/lookup*' => Http::response(['data' => $wire]),
        'api.road.test/api/alpha/authz/scopes/scope_x' => Http::response(['data' => $wire]),
    ]);
    Http::preventStrayRequests();

    $scopes = seedRoadClient()->iam()->scopes();

    expect($scopes->lookup('business_unit', 'ext-1')->catalogScopeId)->toBe('scope_road');
    expect($scopes->get('scope_x'))->toBeInstanceOf(Scope::class);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/authz/scopes/lookup')
        && $r->data() === ['type' => 'business_unit', 'externalId' => 'ext-1']);
});

it('assigns at a scope, with the scope in the path', function () {
    Http::fake([
        'api.road.test/api/alpha/authz/scopes/scope_1/assignments/batch' => Http::response(['data' => [['id' => 'asg_2', 'created' => true], ['id' => 'asg_3', 'created' => false]]], 201),
        'api.road.test/api/alpha/authz/scopes/scope_1/assignments' => Http::response(['data' => assignmentWire('asg_1')], 201),
    ]);
    Http::preventStrayRequests();

    $scope = seedRoadClient()->iam()->scope('scope_1');
    $input = ['subjectType' => 'user', 'subjectId' => 'u_1', 'roleId' => 'role_1'];

    $assignment = $scope->assign($input);
    expect($assignment)->toBeInstanceOf(Assignment::class);
    expect($assignment->roleName)->toBe('Owner');
    expect($scope->assignMany([$input, $input]))->toBe([['id' => 'asg_2', 'created' => true], ['id' => 'asg_3', 'created' => false]]);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/assignments') && $r->data() === $input);
    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/assignments/batch') && $r->data() === ['assignments' => [$input, $input]]);
});

it('lists a subject\'s assignments across every page, and reads and revokes one by id', function () {
    Http::fake([
        'api.road.test/api/alpha/authz/subjects/user/u_1/assignments*' => Http::sequence()
            ->push(['data' => [assignmentWire('asg_1')], 'pagination' => ['cursor' => 'c2', 'hasMore' => true, 'totalCount' => 2]])
            ->push(['data' => [assignmentWire('asg_2')], 'pagination' => ['cursor' => null, 'hasMore' => false, 'totalCount' => 2]]),
        'api.road.test/api/alpha/authz/assignments/asg_1' => Http::sequence()
            ->push(['data' => assignmentWire('asg_1')])
            ->push(['data' => ['id' => 'asg_1', 'revoked' => true]]),
    ]);
    Http::preventStrayRequests();

    $assignments = seedRoadClient()->iam()->assignments();

    expect(array_map(fn (Assignment $a) => $a->id, $assignments->list('user', 'u_1')->all()))->toBe(['asg_1', 'asg_2']);
    expect($assignments->get('asg_1')->scopeId)->toBe('scope_1');
    $assignments->delete('asg_1');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/authz/assignments/asg_1'));
});

it('reads a subject\'s effective permissions at a scope', function () {
    Http::fake(['api.road.test/api/alpha/authz/subjects/service/svc_1/permissions*' => Http::response([
        'data' => ['scopeId' => 'scope_1', 'all' => false, 'codes' => ['read:Member']],
    ])]);
    Http::preventStrayRequests();

    $effective = seedRoadClient()->iam()->scope('scope_1')->effectivePermissions('service', 'svc_1');

    expect($effective->codes)->toBe(['read:Member']);
    expect($effective->all)->toBeFalse();
    Http::assertSent(fn (HttpRequest $r) => $r->data() === ['scopeId' => 'scope_1']);
});
