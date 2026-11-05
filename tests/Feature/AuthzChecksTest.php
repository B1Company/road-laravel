<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Client\HttpTransportInterface;
use B1Road\Laravel\Client\RoadClient;
use B1Road\Laravel\Context\RoadContext;
use B1Road\Laravel\DTO\CheckDecision;
use B1Road\Laravel\DTO\CheckExplanation;
use B1Road\Laravel\Exceptions\RoadApiException;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Exceptions\RoadNotFoundException;
use B1Road\Laravel\Exceptions\RoadValidationException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(ActsAsRoadUser::class);

/** The live transport, for tests that pin what goes on the wire. */
function liveCaller(): void
{
    $context = app(RoadContext::class);
    $context->setUser(new RoadUser(id: 'u_1', email: 'u1@example.com', name: 'User 1'));
    $context->setToken('test-access-token');
    $context->setRequestId('req_test_1');
    Http::preventStrayRequests();
}

function checksAnswer(array $codes, bool $allowed = true): array
{
    return ['data' => ['results' => array_map(
        fn (string $code) => ['code' => $code, 'allowed' => $allowed, 'reason' => $allowed ? 'allowed_role' : 'denied_no_grant'],
        $codes,
    )]];
}

function checkBodies(): array
{
    return Http::recorded()
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/api/alpha/authz/checks'))
        ->map(fn (array $pair) => $pair[0]->data())
        ->values()
        ->all();
}

function platformScenario(): RoadScenario
{
    return RoadScenario::make()
        ->withUser('u_owner')
        ->withUser('u_admin')
        ->withUser('u_member')
        ->withBusinessUnit('bu_1')
        ->withBusinessUnit('bu_unsubscribed')
        ->withRole('bu_1', 'Owner', permissions: ['*'])
        ->withRole('bu_1', 'Admin', permissions: ['manage:Member'])
        ->withRole('bu_1', 'BuRoleEditor', permissions: ['manage:Role'])
        ->withMember('bu_1', 'u_owner', roles: ['Owner'])
        ->withMember('bu_1', 'u_admin', roles: ['Admin'])
        ->withMember('bu_1', 'u_member', roles: [])
        ->withUser('u_bu_editor')
        ->withMember('bu_1', 'u_bu_editor', roles: ['BuRoleEditor'])
        ->withSubscription('bu_1', 'plat_acme',
            roles: ['InvoiceAdmin' => ['manage:Invoice'], 'RoleEditor' => ['manage:Role']],
            members: ['u_admin' => ['InvoiceAdmin'], 'u_member' => ['RoleEditor']],
        );
}

it('in() sends a code to POST /authz/checks by business unit, with no subject and no lookup', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Member']))]);
    liveCaller();

    expect(Road::can(Action::Read, Subject::Member)->in('bu_1')->check())->toBeTrue();

    expect(checkBodies())->toBe([['codes' => ['read:Member'], 'businessUnitId' => 'bu_1']]);
    Http::assertSentCount(1);
});

it('forPlatform() sends the configured platform, before or after in()', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    Road::can('read', 'Invoice')->forPlatform()->in('bu_1')->check();
    Road::can('read', 'Invoice')->in('bu_1')->forPlatform()->check();

    $body = ['codes' => ['read:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'];
    expect(checkBodies())->toBe([$body, $body]);
});

it('the target picks the catalog when a platform code collides with a Road one', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['manage:Role']))]);
    liveCaller();

    Road::can(Action::Manage, Subject::Role)->in('bu_1')->check();
    Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->check();
    Road::can(Action::Manage, Subject::Role)->forPlatform('plat_other')->in('bu_1')->check();

    expect(checkBodies())->toBe([
        ['codes' => ['manage:Role'], 'businessUnitId' => 'bu_1'],
        ['codes' => ['manage:Role'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'],
        ['codes' => ['manage:Role'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_other'],
    ]);
});

it('in() reaches the business unit whatever the code, with no platform configured', function (string $action, string $subject) {
    config(['road.platform_id' => null]);
    Http::fake(['api.road.test/api/alpha/authz/checks' => fn (HttpRequest $request) => Http::response(checksAnswer($request->data()['codes']))]);
    liveCaller();

    Road::can($action, $subject)->in('bu_1')->check();

    expect(checkBodies())->toBe([['codes' => [$action.':'.$subject], 'businessUnitId' => 'bu_1']]);
})->with([
    'a Road code the enum lacks' => ['read', 'Scope'],
    'another one' => ['manage', 'Assignment'],
    "someone else's code" => ['read', 'Invoice'],
]);

it('a code in another case keeps its target and is sent as written', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => fn (HttpRequest $request) => Http::response(checksAnswer($request->data()['codes']))]);
    liveCaller();

    Road::can('read', 'member')->in('bu_1')->check();
    Road::can('read', 'Member')->raw('READ:MEMBER')->in('bu_1')->check();
    Road::can('read', 'member')->forPlatform()->in('bu_1')->check();

    expect(checkBodies())->toBe([
        ['codes' => ['read:member'], 'businessUnitId' => 'bu_1'],
        ['codes' => ['READ:MEMBER'], 'businessUnitId' => 'bu_1'],
        ['codes' => ['read:member'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'],
    ]);
});

it('inScope() names the scope and nothing else', function () {
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    Road::can('read', 'Invoice')->inScope('scope_V1StGXR8_Z5jdHi6B-myT')->check();

    expect(checkBodies())->toBe([['codes' => ['read:Invoice'], 'scopeId' => 'scope_V1StGXR8_Z5jdHi6B-myT']]);
});

it('refuses forPlatform() before any request when no platform is known', function (Closure $check) {
    config(['road.platform_id' => null]);
    Http::fake();
    liveCaller();

    expect($check)->toThrow(LogicException::class, "Set ROAD_PLATFORM_ID, or name it: ->forPlatform('plat_...').");
    Http::assertNothingSent();
})->with([
    'a check' => fn () => Road::can('read', 'Invoice')->forPlatform()->in('bu_1')->check(),
    'a trace' => fn () => Road::can('read', 'Invoice')->forPlatform()->in('bu_1')->trace(),
    'a batch' => fn () => Road::canMany([Road::can('read', 'Invoice')])->forPlatform()->in('bu_1')->resolve(),
]);

it('forPlatform() takes a platform id when none is configured', function () {
    config(['road.platform_id' => null]);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    Road::can('read', 'Invoice')->forPlatform('plat_named')->in('bu_1')->check();

    expect(checkBodies()[0]['platformId'])->toBe('plat_named');
});

it('refuses forPlatform() beside inScope(), which name two targets', function (Closure $check) {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake();
    liveCaller();

    expect($check)->toThrow(LogicException::class, 'two different targets');
    Http::assertNothingSent();
})->with([
    'forPlatform first' => fn () => Road::can('read', 'Invoice')->forPlatform()->inScope('scope_sub')->check(),
    'inScope first' => fn () => Road::can('read', 'Invoice')->inScope('scope_sub')->forPlatform()->check(),
    'on a batch' => fn () => Road::canMany([Road::can('read', 'Invoice')])->forPlatform()->inScope('scope_sub')->resolve(),
]);

it('reads an answer with no verdict as a failure, never as a denial', function (array $answer) {
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response($answer)]);
    liveCaller();

    expect(fn () => Road::can(Action::Read, Subject::Member)->in('bu_1')->check())
        ->toThrow(RoadApiException::class, "verdict for 'read:Member'");
})->with([
    'no results' => [['data' => []]],
    'no allowed' => [['data' => ['results' => [['code' => 'read:Member', 'reason' => 'x']]]]],
    'another code' => [['data' => ['results' => [['code' => 'read:Role', 'allowed' => true, 'reason' => 'x']]]]],
]);

it('sends more than 100 codes in chunks and keeps their order', function () {
    Http::fake(['api.road.test/api/alpha/authz/checks' => function (HttpRequest $request) {
        return Http::response(checksAnswer($request->data()['codes']));
    }]);
    liveCaller();

    $codes = array_map(fn (int $i) => 'read:Thing'.$i, range(1, 101));
    $result = app(RoadClient::class)->iam()->check(['businessUnitId' => 'bu_1', 'codes' => $codes]);

    expect(array_map(fn (CheckDecision $d) => $d->code, $result->results))->toBe($codes);
    expect(array_map(fn (array $body) => count($body['codes']), checkBodies()))->toBe([100, 1]);
});

it('a batch sends each check to its own target, in the order asked', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => function (HttpRequest $request) {
        return Http::response(checksAnswer($request->data()['codes'], isset($request->data()['platformId'])));
    }]);
    liveCaller();

    $allowed = Road::canMany([
        Road::can('read', 'Invoice')->forPlatform(),
        Road::can(Action::Read, Subject::Member),
        Road::can('update', 'Invoice')->forPlatform(),
        Road::can(Action::Manage, Subject::Role),
    ])->in('bu_1')->resolve();

    expect($allowed)->toBe([true, false, true, false]);
    expect(checkBodies())->toBe([
        ['codes' => ['read:Invoice', 'update:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'],
        ['codes' => ['read:Member', 'manage:Role'], 'businessUnitId' => 'bu_1'],
    ]);
});

it('a batch with no forPlatform() checks every code at the business unit', function () {
    config(['road.platform_id' => null]);
    Http::fake(['api.road.test/api/alpha/authz/checks' => fn (HttpRequest $request) => Http::response(checksAnswer($request->data()['codes']))]);
    liveCaller();

    Road::canMany([Road::can('read', 'Invoice'), Road::can('read', 'Scope')])->in('bu_1')->resolve();

    expect(checkBodies())->toBe([['codes' => ['read:Invoice', 'read:Scope'], 'businessUnitId' => 'bu_1']]);
});

it('forPlatform() on a batch moves every check, and an id there wins over a check\'s own', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => fn (HttpRequest $request) => Http::response(checksAnswer($request->data()['codes']))]);
    liveCaller();

    Road::canMany([Road::can('read', 'Invoice'), Road::can(Action::Manage, Subject::Role)])->forPlatform()->in('bu_1')->resolve();
    Road::canMany([Road::can('read', 'Invoice')->forPlatform('plat_own')])->forPlatform()->in('bu_1')->resolve();
    Road::canMany([Road::can('read', 'Invoice')->forPlatform('plat_own')])->forPlatform('plat_batch')->in('bu_1')->resolve();

    expect(checkBodies())->toBe([
        ['codes' => ['read:Invoice', 'manage:Role'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'],
        ['codes' => ['read:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_own'],
        ['codes' => ['read:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_batch'],
    ]);
});

it('a batch can name a scope instead of a business unit', function () {
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    expect(Road::canMany([Road::can('read', 'Invoice')])->inScope('scope_sub')->resolve())->toBe([true]);
    expect(checkBodies())->toBe([['codes' => ['read:Invoice'], 'scopeId' => 'scope_sub']]);
});

it('iam()->explain() returns the decision with what it was made from', function () {
    Http::fake(['api.road.test/api/alpha/authz/checks/explain*' => Http::response(['data' => [
        'code' => 'read:Member',
        'allowed' => false,
        'reason' => 'denied_member_not_active',
        'chain' => ['scope_bu', 'scope_org'],
        'heldRoles' => [['id' => 'asg_1', 'subjectType' => 'user', 'subjectId' => 'u_1', 'roleId' => 'role_1', 'roleName' => 'Admin', 'scopeId' => 'scope_bu', 'grantedBy' => 'u_0', 'grantedAt' => '2026-01-01T00:00:00Z', 'expiresAt' => null]],
    ]])]);
    liveCaller();

    $explanation = app(RoadClient::class)->iam()->explain('scope_bu', 'read:Member');

    expect($explanation)->toBeInstanceOf(CheckExplanation::class);
    expect($explanation->allowed)->toBeFalse();
    expect($explanation->reason)->toBe('denied_member_not_active');
    expect($explanation->chain)->toBe(['scope_bu', 'scope_org']);
    expect($explanation->heldRoles[0]->roleName)->toBe('Admin');
    Http::assertSent(fn (HttpRequest $r) => $r->method() === 'GET'
        && str_contains($r->url(), '/api/alpha/authz/checks/explain')
        && $r->data() === ['scopeId' => 'scope_bu', 'code' => 'read:Member']);
});

it('trace() explains at the subscription scope for a platform code', function () {
    config(['road.platform_id' => 'plat_acme']);
    Road::fake(platformScenario());
    $this->actingAsRoadUser('u_admin');

    $trace = Road::can('read', 'Invoice')->forPlatform()->in('bu_1')->trace();

    expect($trace->verdict)->toBe('allow');
    expect($trace->reason)->toBe('allowed_role');
    expect($trace->scope)->toBe('bu_1');
    expect($trace->evaluatedScopes)->toBe(['sub_plat_acme_bu_1', 'scope_bu_1']);
    expect($trace->grants[0]['permissions'])->toBe(['manage:Invoice', 'create:Invoice', 'read:Invoice', 'update:Invoice', 'delete:Invoice']);
});

it('trace() shows a role that holds everything as *', function () {
    Road::fake(platformScenario());
    $this->actingAsRoadUser('u_owner');

    $trace = Road::can(Action::Read, Subject::Member)->in('bu_1')->trace();

    expect($trace->grants)->toBe([['via' => 'effective', 'permissions' => ['*']]]);
    expect($trace->reason)->toBe('allowed_grants_all');
});

it('Road::assert() carries the engine reason on a denial', function () {
    Road::fake(platformScenario());
    $this->actingAsRoadUser('u_member');

    expect(fn () => Road::assert(Road::can(Action::Read, Subject::Member)->in('bu_1')))
        ->toThrow(RoadAuthzException::class, 'denied_no_grant');
});

describe('denied_unknown_code logs a hint and changes nothing else', function () {
    beforeEach(function () {
        config(['road.platform_id' => 'plat_acme']);
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        Log::spy();
    });

    it('at a business unit, it points at forPlatform()', function () {
        expect(Road::can('read', 'Invoice')->in('bu_1')->result()->allowed)->toBeFalse();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "'read:Invoice' at business unit bu_1")
            && str_contains($message, 'may belong to another catalog')
            && str_contains($message, 'check the target')
            && str_contains($message, 'add forPlatform()'));
    });

    it('at a subscription, it says the code may be Road\'s', function () {
        expect(Road::can('read', 'Member')->forPlatform()->in('bu_1')->check())->toBeFalse();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'the subscription to plat_acme in business unit bu_1')
            && str_contains($message, 'drop forPlatform()'));
    });

    it('at a named scope, it asks after the scope', function () {
        expect(Road::can('read', 'Invoice')->inScope('scope_bu_1')->check())->toBeFalse();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'at scope scope_bu_1')
            && str_contains($message, 'Confirm this is the scope you meant'));
    });

    it('a batch logs it per unknown code', function () {
        expect(Road::canMany([Road::can('read', 'Invoice'), Road::can('read', 'Member')])->in('bu_1')->resolve())->toBe([false, true]);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "'read:Invoice'"));
    });

    it('stays quiet on any other answer', function () {
        Road::can('read', 'Member')->in('bu_1')->check();
        $this->actingAsRoadUser('u_admin');
        Road::can(Action::Read, Subject::Role)->in('bu_1')->check();

        Log::shouldNotHaveReceived('warning');
    });
});

describe('the fake answers like the engine', function () {
    beforeEach(fn () => config(['road.platform_id' => 'plat_acme']));

    it('a platform role answers at the subscription and never at the business unit', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin');

        expect(Road::can('create', 'Invoice')->forPlatform()->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can(Action::Read, Subject::Role)->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('answers a colliding code from the catalog the target names', function () {
        Road::fake(platformScenario());

        // Holds Road's manage:Role at the BU and no platform role.
        $this->actingAsRoadUser('u_bu_editor');
        expect(Road::can(Action::Manage, Subject::Role)->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_no_grant');

        // Holds the platform's manage:Role and nothing at the BU.
        $this->actingAsRoadUser('u_member');
        expect(Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can(Action::Manage, Subject::Role)->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('declares Road codes the Subject enum does not list', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Scope')->in('bu_1')->result()->reason)->toBe('allowed_grants_all');
        expect(Road::can('manage', 'Assignment')->in('bu_1')->result()->reason)->toBe('allowed_grants_all');
        expect(Road::can('read', 'ExtensionInstall')->in('bu_1')->result()->reason)->toBe('allowed_grants_all');
    });

    it('does not declare at a BU what Road declares only at system', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can(Action::Read, Subject::BusinessUnit)->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
        expect(Road::can(Action::Manage, Subject::BusinessUnit)->in('bu_1')->check())->toBeFalse();
        expect(Road::can('read', 'Platform')->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
    });

    it('matches a code in any case, as the engine does', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin'); // manage:Member at the BU, manage:Invoice at the subscription

        expect(Road::can('read', 'member')->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can('read', 'Member')->raw('READ:MEMBER')->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can('read', 'invoice')->forPlatform()->in('bu_1')->result()->reason)->toBe('allowed_role');
    });

    it('lets a role pass only by holding a declared code', function () {
        Road::fake(
            RoadScenario::make()
                ->withUser('u_1')
                ->withBusinessUnit('bu_1')
                ->withRole('bu_1', 'Phantom', permissions: ['manage:Scope'])
                ->withMember('bu_1', 'u_1', roles: ['Phantom']),
        );
        $this->actingAsRoadUser('u_1');

        // Road declares read:Scope and no manage:Scope, so nothing composes it.
        expect(Road::can('read', 'Scope')->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('a BU role with the same code never answers for the platform catalog', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin'); // manage:Member at the BU, none at the subscription

        expect(Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('a BU * passes at the subscription', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Invoice')->forPlatform()->in('bu_1')->result()->reason)->toBe('allowed_grants_all');
    });

    it('a code the catalog does not declare reads as a developer error', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Nothing')->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
        // A platform code aimed at the BU lands in the wrong catalog.
        expect(Road::can('read', 'Invoice')->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
        // A Road code aimed at the subscription does too.
        expect(Road::can(Action::Read, Subject::Member)->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
    });

    it('a business unit with no such subscription reads as a plain no', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Invoice')->forPlatform()->in('bu_unsubscribed')->result()->reason)
            ->toBe('denied_no_grant');
    });

    it('an unknown scope id is denied_unknown_scope', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        // A BU id is not a scope id: passing one to inScope() must not pass.
        expect(Road::can(Action::Read, Subject::Member)->inScope('bu_1')->result()->reason)->toBe('denied_unknown_scope');
    });

    it('a platform code on a BU role reads as unknown, as the API would', function () {
        Road::fake(
            RoadScenario::make()
                ->withUser('u_1')
                ->withBusinessUnit('bu_1')
                ->withRole('bu_1', 'Mixed', permissions: ['read:Project', 'read:BusinessUnit', 'read:Member'])
                ->withMember('bu_1', 'u_1', roles: ['Mixed']),
        );
        $this->actingAsRoadUser('u_1');

        expect(Road::can('read', 'Project')->inScope('scope_bu_1')->result()->reason)->toBe('denied_unknown_code');
        expect(Road::client()->me()->permissions()->byBusinessUnit)->toBe(['bu_1' => ['read:Member']]);
    });

    it('catalog road asks about Road codes at a subscription', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin');

        $checks = app(RoadClient::class)->iam()->check([
            'scopeId' => 'sub_plat_acme_bu_1',
            'catalog' => 'road',
            'codes' => ['read:Member'],
        ]);

        expect($checks->results[0]->reason)->toBe('allowed_role');
    });

    it('catalog road knows every root, and a BU * covers only the BU catalog', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        $reason = fn (string $code) => app(RoadClient::class)->iam()->check([
            'scopeId' => 'sub_plat_acme_bu_1',
            'catalog' => 'road',
            'codes' => [$code],
        ])->results[0]->reason;

        expect($reason('read:Member'))->toBe('allowed_grants_all');
        expect($reason('read:BusinessUnit'))->toBe('denied_no_grant');
        expect($reason('read:PlatformCredential'))->toBe('denied_no_grant');
        expect($reason('read:Invoice'))->toBe('denied_unknown_code');
    });
});

describe('the fake serves the control plane like the API', function () {
    it('refuses a field the API no longer takes', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        $iam = app(RoadClient::class)->iam();

        expect(fn () => $iam->scope('scope_bu_1')->roles()->create(['name' => 'E', 'permissions' => ['read:Member']]))
            ->toThrow(RoadValidationException::class, 'permissions');
        expect(fn () => $iam->scope('scope_bu_1')->assign(['subjectType' => 'user', 'subjectId' => 'u', 'roleId' => 'role_1', 'scopeId' => 'scope_bu_1']))
            ->toThrow(RoadValidationException::class, 'scopeId');
    });

    it('lists a platform role at its subscription scope', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        $roles = app(RoadClient::class)->me()->platformRoles('plat_acme', 'bu_1');

        expect(array_map(fn ($r) => $r->id, $roles))->toBe(['role_InvoiceAdmin', 'role_RoleEditor']);
        expect($roles[0]->scopeId)->toBe('scope_mgmt_plat_acme'); // the owner, above the subscription
    });

    it('refuses a role read with no scope, for a role owned above', function (string $path) {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(fn () => app(HttpTransportInterface::class)->request('GET', $path))
            ->toThrow(RoadAuthzException::class);
    })->with(['the role' => '/authz/roles/role_Owner', 'its permissions' => '/authz/roles/role_Owner/permissions']);

    it('answers a role read from a scope that offers it', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        $iam = app(RoadClient::class)->iam();

        expect($iam->scope('scope_bu_1')->roles()->get('role_Admin')->scopeId)->toBe('scope_road');
        expect($iam->scope('sub_plat_acme_bu_1')->roles()->get('role_InvoiceAdmin')->scopeId)->toBe('scope_mgmt_plat_acme');
        expect($iam->scope('sub_plat_acme_bu_1')->roles()->permissions('role_InvoiceAdmin')->all()[0]->code)->toBe('manage:Invoice');
    });

    it('is a 404 at a scope that does not offer the role', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        $iam = app(RoadClient::class)->iam();

        expect(fn () => $iam->scope('scope_bu_1')->roles()->get('role_InvoiceAdmin'))->toThrow(RoadNotFoundException::class);
        expect(fn () => $iam->scope('sub_plat_acme_bu_1')->roles()->permissions('role_Admin')->all())->toThrow(RoadNotFoundException::class);
        expect(fn () => $iam->scope('scope_bu_1')->roles()->get('role_Nothing'))->toThrow(RoadNotFoundException::class);
    });

    it('is a 403 for a reader without read:Role at the scope, before the 404', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_member');
        $iam = app(RoadClient::class)->iam();

        expect(fn () => $iam->scope('scope_bu_1')->roles()->get('role_Admin'))->toThrow(RoadAuthzException::class);
        expect(fn () => $iam->scope('scope_bu_1')->roles()->get('role_InvoiceAdmin'))->toThrow(RoadAuthzException::class);
    });

    it('reports what each user holds, at the scope they hold it', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        $iam = app(RoadClient::class)->iam();

        $held = array_map(fn ($a) => [$a->roleName, $a->scopeId], $iam->assignments()->list('user', 'u_admin')->all());

        expect($held)->toBe([['Admin', 'scope_bu_1'], ['InvoiceAdmin', 'sub_plat_acme_bu_1']]);
        expect(array_map(fn ($a) => $a->subjectId, $iam->scope('sub_plat_acme_bu_1')->assignments()->all()))->toBe(['u_admin', 'u_member']);
    });

    it('answers effective permissions and a role composition from the scenario', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');
        $iam = app(RoadClient::class)->iam();

        expect($iam->scope('scope_bu_1')->effectivePermissions('user', 'u_owner')->all)->toBeTrue();
        expect($iam->scope('sub_plat_acme_bu_1')->effectivePermissions('user', 'u_member')->codes)->toContain('create:Role');
        expect(array_map(fn ($p) => $p->code, $iam->scope('scope_bu_1')->roles()->permissions('role_Admin')->all()))->toBe(['manage:Member']);
    });
});
