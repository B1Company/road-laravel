<?php

declare(strict_types=1);

use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadConflictException;
use B1Road\Laravel\Exceptions\RoadValidationException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadFakeAssertions;
use B1Road\Laravel\Testing\RoadScenario;

/*
 * The fake must refuse an invitation the way the API does (B1-761): the link
 * token is the proof, a resend rotates it, and accepting spends it. Before
 * this, `accept()` answered 200 whatever token it got.
 */

uses(ActsAsRoadUser::class);

function fakeWithInvitation(?string $token = null): RoadFakeAssertions
{
    $fake = Road::fake(
        RoadScenario::make()
            ->withUser('u', email: 'u@b1.app', name: 'U')
            ->withBusinessUnit('bu_1', iamScopeId: 'scope_1')
            ->withMember('bu_1', 'u', roles: ['Owner'])
            ->withInvitation('bu_1', 'invitee@b1.app', id: 'inv_1', token: $token)
    );
    test()->actingAsRoadUser('u');

    return $fake;
}

/** Run the call and return what it threw (or null). */
function thrownBy(callable $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** The declared invitation's status as the fake lists it now. */
function listedStatus(string $id): ?string
{
    foreach (app(RoadClient::class)->businessUnits('bu_1')->invitations()->all() as $invitation) {
        if ($invitation->id === $id) {
            return $invitation->status;
        }
    }

    return null;
}

it('refuses an empty token with 400 and changes nothing', function () {
    fakeWithInvitation();

    $e = thrownBy(fn () => app(RoadClient::class)->invitations()->accept('inv_1', ''));

    expect($e)->toBeInstanceOf(RoadValidationException::class)
        ->and($e->status)->toBe(400)
        ->and($e->fieldErrors)->toHaveKey('token')
        ->and(listedStatus('inv_1'))->toBe('pending');
});

it('refuses a body with no token at all with 400', function () {
    fakeWithInvitation();

    $e = thrownBy(fn () => app(HttpTransportInterface::class)
        ->request('POST', '/organization/invitations/inv_1/accept', []));

    expect($e)->toBeInstanceOf(RoadValidationException::class)
        ->and($e->fieldErrors)->toHaveKey('token');
});

it('refuses a wrong token with 403 INVITATION_LINK_INVALID and changes nothing', function () {
    fakeWithInvitation();

    $e = thrownBy(fn () => app(RoadClient::class)->invitations()->accept('inv_1', 'test-invite-token-not-real'));

    expect($e)->toBeInstanceOf(RoadAuthzException::class)
        ->and($e->errorCode())->toBe('INVITATION_LINK_INVALID')
        ->and(listedStatus('inv_1'))->toBe('pending');
});

it('accepts the token from the invitation link', function () {
    $fake = fakeWithInvitation();

    $accepted = app(RoadClient::class)->invitations()->accept('inv_1', $fake->invitationToken('inv_1'));

    expect($accepted->status)->toBe('accepted')
        ->and($accepted->acceptedVia)->toBe('manual')
        ->and(listedStatus('inv_1'))->toBe('accepted');
});

it('takes the token the scenario declares', function () {
    $fake = fakeWithInvitation('test-invite-token-not-real');

    expect($fake->invitationToken('inv_1'))->toBe('test-invite-token-not-real');
    expect(app(RoadClient::class)->invitations()->accept('inv_1', 'test-invite-token-not-real')->status)
        ->toBe('accepted');
});

it('refuses the same token a second time with 409 INVALID_STATUS_TRANSITION', function () {
    $fake = fakeWithInvitation();
    $token = $fake->invitationToken('inv_1');
    app(RoadClient::class)->invitations()->accept('inv_1', $token);

    $e = thrownBy(fn () => app(RoadClient::class)->invitations()->accept('inv_1', $token));

    expect($e)->toBeInstanceOf(RoadConflictException::class)
        ->and($e->errorCode())->toBe('INVALID_STATUS_TRANSITION');
});

it('refuses an unknown invitation with 409 INVITATION_NOT_FOUND', function () {
    fakeWithInvitation();

    $e = thrownBy(fn () => app(RoadClient::class)->invitations()->accept('inv_nope', 'test-invite-token-not-real'));

    expect($e)->toBeInstanceOf(RoadConflictException::class)
        ->and($e->errorCode())->toBe('INVITATION_NOT_FOUND');
});

it('rotates the token on resend: the old link is refused, the new one works', function () {
    $fake = fakeWithInvitation();
    $before = $fake->invitationToken('inv_1');

    app(HttpTransportInterface::class)
        ->request('POST', '/organization/business-units/bu_1/invitations/inv_1/resend');
    $after = $fake->invitationToken('inv_1');
    expect($after)->not->toBe($before);

    $e = thrownBy(fn () => app(RoadClient::class)->invitations()->accept('inv_1', $before));
    expect($e)->toBeInstanceOf(RoadAuthzException::class)
        ->and($e->errorCode())->toBe('INVITATION_LINK_INVALID');

    expect(app(RoadClient::class)->invitations()->accept('inv_1', $after)->status)->toBe('accepted');
});

it('never returns a link token in a response', function () {
    $fake = fakeWithInvitation();
    $scope = app(RoadClient::class)->businessUnits('bu_1');
    $created = $scope->invitations()->create(['email' => 'x@b1.app', 'roleId' => 'r_1']);
    $tokens = [$fake->invitationToken('inv_1'), $fake->invitationToken($created->id)];

    $wire = json_encode([
        app(HttpTransportInterface::class)->request('GET', '/organization/business-units/bu_1/invitations'),
        app(HttpTransportInterface::class)->request('POST', '/organization/business-units/bu_1/invitations', ['email' => 'y@b1.app']),
    ]);

    foreach ($tokens as $token) {
        expect($wire)->not->toContain($token);
    }
    expect($wire)->not->toContain('fake-invite-token');
});
