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
