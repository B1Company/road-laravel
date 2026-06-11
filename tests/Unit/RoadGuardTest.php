<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadGuard;
use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Auth\RoadUserProvider;
use B1Road\Laravel\Context\RoadContext;

function makeRoadUser(): RoadUser
{
    return new RoadUser(id: 'u_1', email: 'u1@b1.app', name: 'User One', payload: ['sub' => 'u_1', 'role' => 'admin']);
}

it('answers RoadUserProvider lookups from the RoadContext', function () {
    $context = new RoadContext;
    $provider = new RoadUserProvider($context);

    expect($provider->retrieveById('u_1'))->toBeNull(); // no user yet

    $user = makeRoadUser();
    $context->setUser($user);

    expect($provider->retrieveById('u_1'))->toBe($user);
    expect($provider->retrieveById('someone-else'))->toBeNull();
    expect($provider->retrieveByToken('u_1', 'tok'))->toBeNull();
    expect($provider->retrieveByCredentials([]))->toBeNull();
    expect($provider->validateCredentials($user, []))->toBeFalse();

    // Read-only no-ops — exercised for completeness.
    $provider->updateRememberToken($user, 'tok');
    $provider->rehashPasswordIfRequired($user, []);
    expect(true)->toBeTrue();
});

it('reads the user from the RoadContext through the RoadGuard', function () {
    $context = new RoadContext;
    $guard = new RoadGuard(new RoadUserProvider($context), $context);

    expect($guard->hasUser())->toBeFalse();
    expect($guard->validate(['email' => 'x']))->toBeFalse();

    $context->setUser(makeRoadUser());

    expect($guard->user())->not->toBeNull();
    expect($guard->hasUser())->toBeTrue();
});

it('serialises the RoadUser to its public array shape', function () {
    $array = makeRoadUser()->toArray();

    expect($array['id'])->toBe('u_1');
    expect($array['email'])->toBe('u1@b1.app');
    expect($array['name'])->toBe('User One');
});
