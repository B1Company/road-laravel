<?php

declare(strict_types=1);

use B1Road\Laravel\Auth\RoadUser;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\Subject;
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
        ->withMember('bu_1', 'u_owner', roles: ['Owner'])
        ->withMember('bu_1', 'u_admin', roles: ['Admin'])
        ->withMember('bu_1', 'u_member', roles: [])
        ->withSubscription('bu_1', 'plat_acme',
            roles: ['InvoiceAdmin' => ['manage:Invoice'], 'RoleEditor' => ['manage:Role']],
            members: ['u_admin' => ['InvoiceAdmin'], 'u_member' => ['RoleEditor']],
        );
}

it('sends a Road code to POST /authz/checks by business unit, with no subject and no lookup', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Member']))]);
    liveCaller();

    expect(Road::can(Action::Read, Subject::Member)->in('bu_1')->check())->toBeTrue();

    expect(checkBodies())->toBe([['codes' => ['read:Member'], 'businessUnitId' => 'bu_1']]);
    Http::assertSentCount(1);
});

it('sends a platform code with the configured platform', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    Road::can('read', 'Invoice')->in('bu_1')->check();

    expect(checkBodies())->toBe([['codes' => ['read:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme']]);
});

it('treats a Road subject passed as a string as Road, as the middleware does', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Member']))]);
    liveCaller();

    Road::can('read', 'Member')->in('bu_1')->check();

    expect(checkBodies()[0])->not->toHaveKey('platformId');
});

it('forPlatform() checks your own code that shares a name with a Road one', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['manage:Role']))]);
    liveCaller();

    Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->check();
    Road::can(Action::Manage, Subject::Role)->forPlatform('plat_other')->in('bu_1')->check();

    expect(checkBodies()[0]['platformId'])->toBe('plat_acme');
    expect(checkBodies()[1]['platformId'])->toBe('plat_other');
});

it('inScope() names the scope and nothing else', function () {
    Http::fake(['api.road.test/api/alpha/authz/checks' => Http::response(checksAnswer(['read:Invoice']))]);
    liveCaller();

    Road::can('read', 'Invoice')->inScope('11111111-1111-1111-1111-111111111111')->check();

    expect(checkBodies())->toBe([['codes' => ['read:Invoice'], 'scopeId' => '11111111-1111-1111-1111-111111111111']]);
});

it('refuses a platform code before any request when no platform is known', function () {
    config(['road.platform_id' => null]);
    Http::fake();
    liveCaller();

    expect(fn () => Road::can('read', 'Invoice')->in('bu_1')->check())
        ->toThrow(LogicException::class, 'ROAD_PLATFORM_ID');
    Http::assertNothingSent();
});

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

it('a batch answers each code from its own catalog, in the order asked', function () {
    config(['road.platform_id' => 'plat_acme']);
    Http::fake(['api.road.test/api/alpha/authz/checks' => function (HttpRequest $request) {
        return Http::response(checksAnswer($request->data()['codes'], isset($request->data()['platformId'])));
    }]);
    liveCaller();

    $allowed = Road::canMany([
        Road::can('read', 'Invoice'),
        Road::can(Action::Read, Subject::Member),
        Road::can('update', 'Invoice'),
    ])->in('bu_1')->resolve();

    expect($allowed)->toBe([true, false, true]);
    expect(checkBodies())->toBe([
        ['codes' => ['read:Invoice', 'update:Invoice'], 'businessUnitId' => 'bu_1', 'platformId' => 'plat_acme'],
        ['codes' => ['read:Member'], 'businessUnitId' => 'bu_1'],
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

    $trace = Road::can('read', 'Invoice')->in('bu_1')->trace();

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

describe('the fake answers like the engine', function () {
    beforeEach(fn () => config(['road.platform_id' => 'plat_acme']));

    it('a platform role answers at the subscription and never at the business unit', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin');

        expect(Road::can('create', 'Invoice')->in('bu_1')->result()->reason)->toBe('allowed_role');
        expect(Road::can(Action::Read, Subject::Role)->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('a BU role with the same code never answers for the platform catalog', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_admin'); // manage:Member at the BU, none at the subscription

        expect(Road::can(Action::Manage, Subject::Role)->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_no_grant');
    });

    it('a BU * passes at the subscription', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Invoice')->in('bu_1')->result()->reason)->toBe('allowed_grants_all');
    });

    it('a code the catalog does not declare reads as a developer error', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(Road::can('read', 'Nothing')->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
        // A Road code sent as a platform one lands in the wrong catalog.
        expect(Road::can(Action::Read, Subject::Member)->forPlatform()->in('bu_1')->result()->reason)->toBe('denied_unknown_code');
    });

    it('a business unit with no such subscription is a 404, not a denial', function () {
        Road::fake(platformScenario());
        $this->actingAsRoadUser('u_owner');

        expect(fn () => Road::can('read', 'Invoice')->in('bu_unsubscribed')->check())
            ->toThrow(RoadNotFoundException::class);
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
                ->withRole('bu_1', 'Mixed', permissions: ['read:Project', 'read:Member'])
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
        expect($roles[0]->scopeId)->toBe('sub_plat_acme_bu_1');
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
