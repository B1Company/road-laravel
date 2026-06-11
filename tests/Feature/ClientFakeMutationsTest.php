<?php

declare(strict_types=1);

use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\DTO\Assignment;
use B1Road\Laravel\DTO\EffectivePermissionsResult;
use B1Road\Laravel\DTO\Invitation;
use B1Road\Laravel\DTO\Role;
use B1Road\Laravel\DTO\Scope;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;

uses(ActsAsRoadUser::class);

function fakeFullScenario(): RoadFakeAssertions
{
    $fake = Road::fake(
        RoadScenario::make()
            ->withUser('u', email: 'u@b1.app', name: 'U')
            ->withBusinessUnit('bu_1', iamScopeId: 'scope_1')
            ->withRole('bu_1', 'Owner', ['*'])
            ->withMember('bu_1', 'u', roles: ['Owner'])
            ->withInvitation('bu_1', 'invitee@b1.app')
    );
    test()->actingAsRoadUser('u');

    return $fake;
}

it('drives the whole client surface through the in-memory fake', function () {
    $fake = fakeFullScenario();
    $client = app(RoadClient::class);
    $scope = $client->businessUnits('bu_1');

    // Members
    expect($scope->members()->all())->toHaveCount(1);
    $scope->members()->get('mem_bu_1_u');
    $scope->members()->suspend('mem_bu_1_u');
    $scope->members()->reinstate('mem_bu_1_u');
    $scope->members()->remove('mem_bu_1_u');

    // Roles (scope-keyed)
    expect($client->iam()->scope('scope_1')->roles()->all())->not->toBeEmpty();
    expect($client->iam()->scope('scope_1')->roles()->get('r_Owner'))->toBeInstanceOf(Role::class);
    $client->iam()->scope('scope_1')->roles()->create(['name' => 'Editor', 'permissions' => ['read:Member']]);
    $client->iam()->scope('scope_1')->roles()->update('r_Owner', ['name' => 'Owner2']);
    $client->iam()->scope('scope_1')->roles()->delete('r_Owner');

    // Invitations
    expect($scope->invitations()->all())->toHaveCount(1);
    expect($scope->invitations()->create(['email' => 'x@b1.app', 'roleId' => 'r_1']))->toBeInstanceOf(Invitation::class);
    expect($scope->invitations()->cancel('inv_bu_1_0')->status)->toBe('cancelled');
    expect($client->invitations()->accept('inv_bu_1_0')->status)->toBe('accepted');
    expect($client->invitations()->reject('inv_bu_1_0')->status)->toBe('rejected');

    // IAM scopes + assignments + effective permissions
    expect($client->iam()->scopes()->create(['type' => 'business_unit']))->toBeInstanceOf(Scope::class);
    expect($client->iam()->scopes()->get('scope_1'))->toBeInstanceOf(Scope::class);
    expect($client->iam()->scopes()->lookup('business_unit', 'ext-1'))->toBeInstanceOf(Scope::class);
    expect($client->iam()->assignments()->create(['subjectType' => 'user', 'subjectId' => 'u', 'roleId' => 'r_1', 'scopeId' => 'scope_1']))->toBeInstanceOf(Assignment::class);
    expect($client->iam()->assignments()->list('user', 'u'))->toBeArray();
    $client->iam()->assignments()->delete('as_1');
    expect($client->iam()->scope('scope_1')->effectivePermissions('user', 'u'))->toBeInstanceOf(EffectivePermissionsResult::class);

    // Authorize (the user holds '*' on bu_1)
    expect($client->iam()->authorize(['subjectType' => 'user', 'subjectId' => 'u', 'scopeId' => 'scope_1', 'permission' => 'read:Member'])->allowed)->toBeTrue();
    expect($client->iam()->authorizeBatch(['subjectType' => 'user', 'subjectId' => 'u', 'scopeId' => 'scope_1', 'permissions' => ['read:Member']])->results)->toHaveCount(1);

    $fake->assertCalled('POST', '/iam/authorization/scopes/scope_1/roles');
    $fake->assertCalled('DELETE', '/organization/business-units/bu_1/members/mem_bu_1_u');
});

it('routes authorization checks through the fake and records the call', function () {
    $fake = fakeFullScenario();

    expect(Road::can(Action::Read, Subject::Member)->in('bu_1')->check())->toBeTrue();
    $fake->assertCalled('POST', '/iam/authorization/authorize');
});

it('supports assertNothingCalled and assertCallCount', function () {
    $fake = Road::fake(RoadScenario::make()->withUser('u')->withBusinessUnit('bu_1'));
    $this->actingAsRoadUser('u');

    $fake->assertNothingCalled();

    app(RoadClient::class)->businessUnits('bu_1')->fetch();
    $fake->assertCallCount(1);
});
