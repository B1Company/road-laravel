<?php

declare(strict_types=1);

use B1Road\Laravel\Attributes\RequirePermission;
use B1Road\Laravel\Authorization\Action;
use B1Road\Laravel\Authorization\DecisionTrace;
use B1Road\Laravel\Authorization\Subject;
use B1Road\Laravel\Exceptions\RoadAuthzException;
use B1Road\Laravel\Facades\Road;
use B1Road\Laravel\Testing\ActsAsRoadUser;
use B1Road\Laravel\Testing\RoadScenario;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Route;

uses(ActsAsRoadUser::class);

function scenarioWithReader(): RoadScenario
{
    return RoadScenario::make()
        ->withUser('u_reader', email: 'r@b1.app')
        ->withUser('u_guest', email: 'g@b1.app')
        ->withBusinessUnit('bu_1')
        ->withRole('bu_1', 'Reader', permissions: ['read:Member', 'read:Role'])
        ->withMember('bu_1', 'u_reader', roles: ['Reader']);
}

it('Road::can()->check() returns true when the role grants the permission', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    expect(Road::can(Action::Read, Subject::Member)->in('bu_1')->check())->toBeTrue();
    expect(Road::can(Action::Update, Subject::Member)->in('bu_1')->check())->toBeFalse();
});

it('Road::can()->trace() returns a DecisionTrace mirroring the NestJS shape', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    $trace = Road::can(Action::Read, Subject::Member)->in('bu_1')->trace();

    expect($trace)->toBeInstanceOf(DecisionTrace::class);
    expect($trace->verdict)->toBe('allow');
    expect($trace->required)->toBe(['read:Member']);
    expect($trace->scope)->toBe('bu_1');
    expect($trace->grants)->not->toBeEmpty();
});

it('Road::assert() throws RoadAuthzException with a trace on deny', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_guest'); // u_guest has no role on bu_1

    try {
        Road::assert(Road::can(Action::Read, Subject::Member)->in('bu_1'));
        expect(false)->toBeTrue('expected RoadAuthzException');
    } catch (RoadAuthzException $e) {
        expect($e->httpStatus())->toBe(403);
        expect($e->errorCode())->toBe('permission_denied');
        expect($e->trace)->toBeInstanceOf(DecisionTrace::class);
        expect($e->trace->verdict)->toBe('deny');
        // Rendered message includes the multi-line trace (Stripe-grade)
        expect($e->getMessage())->toContain('Required:   read:Member');
        expect($e->getMessage())->toContain('Subject:    user:u_guest');
    }
});

it('Road::canMany batches multiple checks into one round-trip', function () {
    $fake = Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    $allowed = Road::canMany([
        Road::can(Action::Read, Subject::Member),
        Road::can(Action::Update, Subject::Role),
        Road::can(Action::Read, Subject::Role),
    ])->in('bu_1')->resolve();

    expect($allowed)->toBe([true, false, true]);
    $fake->assertCalled('POST', '/iam/authorization/authorize/batch');
});

it('the road.permission middleware allows when the role grants the permission', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    Route::middleware(['road.errors', 'road', 'road.permission:read,Member,buId'])
        ->get('/bus/{buId}/members', fn (string $buId) => ['ok' => true, 'bu' => $buId]);

    $this->getJson('/bus/bu_1/members')
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('the road.permission middleware reads the scope from request input with input:', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    Route::middleware(['road.errors', 'road', 'road.permission:read,Member,input:business_unit_id'])
        ->post('/members', fn () => ['ok' => true]);

    $this->postJson('/members', ['business_unit_id' => 'bu_1'])
        ->assertOk()
        ->assertJsonPath('ok', true);
});

it('the road.permission middleware 403s with permission_denied when the role lacks it', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_guest');

    Route::middleware(['road.errors', 'road', 'road.permission:read,Member,buId'])
        ->get('/bus/{buId}/members', fn () => ['ok' => true]);

    $this->getJson('/bus/bu_1/members')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied')
        ->assertJsonPath('error.decision.verdict', 'deny');
});

class AuthzTestMembersController extends Controller
{
    #[RequirePermission(Action::Read, Subject::Member, in: 'buId')]
    public function index(string $buId): array
    {
        return ['ok' => true, 'bu' => $buId];
    }
}

it('the #[RequirePermission] attribute enforces against the same code path', function () {
    Road::fake(scenarioWithReader());

    Route::middleware(['road.errors', 'road', 'road.permission.attribute'])
        ->get('/bus/{buId}/members-attr', [AuthzTestMembersController::class, 'index']);

    // Allowed user
    $this->actingAsRoadUser('u_reader');
    $this->getJson('/bus/bu_1/members-attr')
        ->assertOk()
        ->assertJsonPath('ok', true);

    // Denied user — refresh container so RoadContext re-resolves
    $this->actingAsRoadUser('u_guest');
    $this->getJson('/bus/bu_1/members-attr')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied');
});

it('wildcard role grants every action on every subject in the scope', function () {
    Road::fake(
        RoadScenario::make()
            ->withUser('u_owner', email: 'o@b1.app')
            ->withBusinessUnit('bu_w')
            ->withRole('bu_w', 'Owner', permissions: ['*'])
            ->withMember('bu_w', 'u_owner', roles: ['Owner'])
    );
    $this->actingAsRoadUser('u_owner');

    expect(Road::can(Action::Manage, Subject::BusinessUnit)->in('bu_w')->check())->toBeTrue();
    expect(Road::can(Action::Delete, Subject::Role)->in('bu_w')->check())->toBeTrue();
});

it('manage:Subject grants every CRUD verb on that subject', function () {
    Road::fake(
        RoadScenario::make()
            ->withUser('u_mgr', email: 'm@b1.app')
            ->withBusinessUnit('bu_m')
            ->withRole('bu_m', 'MemberManager', permissions: ['manage:Member'])
            ->withMember('bu_m', 'u_mgr', roles: ['MemberManager'])
    );
    $this->actingAsRoadUser('u_mgr');

    expect(Road::can(Action::Read, Subject::Member)->in('bu_m')->check())->toBeTrue();
    expect(Road::can(Action::Create, Subject::Member)->in('bu_m')->check())->toBeTrue();
    expect(Road::can(Action::Update, Subject::Member)->in('bu_m')->check())->toBeTrue();
    expect(Road::can(Action::Delete, Subject::Member)->in('bu_m')->check())->toBeTrue();
    // manage:Member does NOT spill into manage:Role
    expect(Road::can(Action::Update, Subject::Role)->in('bu_m')->check())->toBeFalse();
});

it('Road::client()->me()->permissions() maps scope tuples to strings keyed by BU id', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    $perms = Road::client()->me()->permissions();

    // Reader holds read:Member + read:Role on bu_1; keyed back by BU id.
    expect($perms->byBusinessUnit)->toBe([
        'bu_1' => ['read:Member', 'read:Role'],
    ]);
});

it('Road::can()->in($buId) resolves the BU to its IAM scope before authorizing', function () {
    $fake = Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    // The fake matches the authorize call ONLY by iamScopeId, so a true
    // verdict proves the SDK resolved bu_1 -> its scope first.
    expect(Road::can(Action::Read, Subject::Member)->in('bu_1')->check())->toBeTrue();

    $fake->assertCalled('GET', '/organization/business-units/bu_1');
    $fake->assertCalled('POST', '/iam/authorization/authorize');
});

it('Road::can()->trace() sources grants from the caller effective permissions', function () {
    Road::fake(scenarioWithReader());
    $this->actingAsRoadUser('u_reader');

    $trace = Road::can(Action::Read, Subject::Member)->in('bu_1')->trace();

    // No role attribution (NFR-14): a single 'effective' grant carrying the
    // caller's actual permissions on the scope.
    expect($trace->grants)->toHaveCount(1);
    expect($trace->grants[0]['via'])->toBe('effective');
    expect($trace->grants[0]['permissions'])->toContain('read:Member');
    expect($trace->grants[0]['permissions'])->toContain('read:Role');
});

// ── Platform-defined string subjects (parity with @b1-road/nestjs) ──────────

function scenarioWithProjectRoles(): RoadScenario
{
    return RoadScenario::make()
        ->withUser('u_admin', email: 'admin@b1.app')
        ->withUser('u_viewer', email: 'viewer@b1.app')
        ->withUser('u_guest', email: 'guest@b1.app')
        ->withBusinessUnit('bu_1')
        ->withRole('bu_1', 'Admin', permissions: ['manage:Project'])
        ->withRole('bu_1', 'Viewer', permissions: ['read:Project'])
        ->withMember('bu_1', 'u_admin', roles: ['Admin'])
        ->withMember('bu_1', 'u_viewer', roles: ['Viewer'])
        ->withMember('bu_1', 'u_guest', roles: []);
}

it('Road::can() accepts a platform-defined string subject', function () {
    Road::fake(scenarioWithProjectRoles());

    $this->actingAsRoadUser('u_admin');
    expect(Road::can(Action::Create, 'Project')->in('bu_1')->permissionString())->toBe('create:Project');
    expect(Road::can(Action::Create, 'Project')->in('bu_1')->check())->toBeTrue(); // manage:Project expands
    expect(Road::can(Action::Delete, 'Project')->in('bu_1')->check())->toBeTrue();

    $this->actingAsRoadUser('u_viewer');
    expect(Road::can(Action::Read, 'Project')->in('bu_1')->check())->toBeTrue();
    expect(Road::can(Action::Create, 'Project')->in('bu_1')->check())->toBeFalse();
});

class AuthzProjectController extends Controller
{
    #[RequirePermission(Action::Read, 'Project', in: 'buId')]
    public function index(string $buId): array
    {
        return ['ok' => true, 'bu' => $buId];
    }
}

it('the #[RequirePermission] attribute enforces a string subject', function () {
    Road::fake(scenarioWithProjectRoles());

    Route::middleware(['road.errors', 'road', 'road.permission.attribute'])
        ->get('/bus/{buId}/projects-attr', [AuthzProjectController::class, 'index']);

    $this->actingAsRoadUser('u_viewer');
    $this->getJson('/bus/bu_1/projects-attr')
        ->assertOk()
        ->assertJsonPath('ok', true);

    // Refresh container so RoadContext re-resolves to the denied user.
    $this->actingAsRoadUser('u_guest');
    $this->getJson('/bus/bu_1/projects-attr')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied');
});

it('the road.permission middleware enforces a string subject', function () {
    Road::fake(scenarioWithProjectRoles());

    Route::middleware(['road.errors', 'road', 'road.permission:create,Project,buId'])
        ->post('/bus/{buId}/projects', fn (string $buId) => ['ok' => true]);

    $this->actingAsRoadUser('u_admin');
    $this->postJson('/bus/bu_1/projects')
        ->assertOk()
        ->assertJsonPath('ok', true);

    // Viewer holds only read:Project → create is denied.
    $this->actingAsRoadUser('u_viewer');
    $this->postJson('/bus/bu_1/projects')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'permission_denied');
});
