<?php

declare(strict_types=1);

use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\DTO\Invitation;
use B1Road\Laravel\DTO\Member;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;

uses(ActsAsRoadUser::class);

it('serves members() from declared memberships through the in-memory fake', function () {
    $fake = Road::fake(
        RoadScenario::make()
            ->withUser('u_owner', email: 'owner@b1.app', name: 'Owner')
            ->withUser('u_member', email: 'member@b1.app', name: 'Member')
            ->withBusinessUnit('bu_1', name: 'B1')
            ->withMember('bu_1', 'u_owner', roles: ['Owner'])
            ->withMember('bu_1', 'u_member', roles: ['Member'])
    );
    $this->actingAsRoadUser('u_owner');

    $members = iterator_to_array(app(RoadClient::class)->businessUnits('bu_1')->members());

    expect($members)->toHaveCount(2);
    expect($members[0])->toBeInstanceOf(Member::class);
    expect(collect($members)->pluck('email')->all())
        ->toContain('owner@b1.app', 'member@b1.app');
    $fake->assertCalled('GET', '/organization/business-units/bu_1/members');
});

it('serves roles() from withRole via scope resolution', function () {
    Road::fake(
        RoadScenario::make()
            ->withUser('u_owner')
            ->withBusinessUnit('bu_1', iamScopeId: 'scope_1')
            ->withRole('bu_1', 'Owner', ['read:Member', 'manage:Role'])
            ->withRole('bu_1', 'Viewer', ['read:Member'])
            ->withMember('bu_1', 'u_owner', roles: ['Owner'])
    );
    $this->actingAsRoadUser('u_owner');

    $roles = app(RoadClient::class)->businessUnits('bu_1')->roles()->all();

    expect(collect($roles)->pluck('name')->all())->toContain('Owner', 'Viewer');
});

it('serves invitations() from withInvitation', function () {
    $fake = Road::fake(
        RoadScenario::make()
            ->withUser('u_owner')
            ->withBusinessUnit('bu_1')
            ->withMember('bu_1', 'u_owner', roles: ['Owner'])
            ->withInvitation('bu_1', 'invitee@b1.app', roleName: 'Member')
    );
    $this->actingAsRoadUser('u_owner');

    $invitations = app(RoadClient::class)->businessUnits('bu_1')->invitations()->all();

    expect($invitations)->toHaveCount(1);
    expect($invitations[0])->toBeInstanceOf(Invitation::class);
    expect($invitations[0]->email)->toBe('invitee@b1.app');
    $fake->assertCalled('GET', '/organization/business-units/bu_1/invitations');
});
