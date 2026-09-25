<?php

use App\Enums\Role;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Users\SuperAdmins;
use Tests\Support\Jurisdiction\JurisdictionWorld;

beforeEach(function () {
    $this->world = JurisdictionWorld::build();
    // A user inside state A1 (visible to A1 roles) and one in zone B.
    $this->inJurisdiction = User::factory()->lgaChief($this->world->lgas['A1b'])->create();
    $this->outOfJurisdiction = User::factory()->lgaChief($this->world->lgas['B1'])->create();
});

/*
 * Expected abilities per actor. Written from the Phase 1B permission table
 * and CLAUDE.md, not derived from the code.
 *   view_in:  view a user in state A1 (another LGA)
 *   view_out: view a user in zone B
 *   manage:   create / update / changeRole / resetTwoFactor
 */
dataset('actors', [
    'super admin' => ['super_admin', ['viewAny' => true, 'view_in' => true, 'view_out' => true, 'manage' => true]],
    'director general' => ['director_general', ['viewAny' => true, 'view_in' => true, 'view_out' => true, 'manage' => false]],
    'DDG national' => ['ddg_national', ['viewAny' => true, 'view_in' => true, 'view_out' => true, 'manage' => false]],
    'DDG zone A' => ['ddg_zone_a', ['viewAny' => true, 'view_in' => true, 'view_out' => false, 'manage' => false]],
    'state director A1' => ['state_director', ['viewAny' => true, 'view_in' => true, 'view_out' => false, 'manage' => false]],
    'deputy state director A1' => ['deputy_state_director', ['viewAny' => true, 'view_in' => true, 'view_out' => false, 'manage' => false]],
    'LGA chief A1a' => ['lga_chief', ['viewAny' => false, 'view_in' => false, 'view_out' => false, 'manage' => false]],
    'inactive DG' => ['inactive_dg', ['viewAny' => false, 'view_in' => false, 'view_out' => false, 'manage' => false]],
    'locked DG' => ['locked_dg', ['viewAny' => false, 'view_in' => false, 'view_out' => false, 'manage' => false]],
    'no role' => ['no_role', ['viewAny' => false, 'view_in' => false, 'view_out' => false, 'manage' => false]],
]);

test('user abilities per role', function (string $who, array $expected) {
    $actor = $this->world->users[$who];
    $inactiveTarget = User::factory()->lgaChief($this->world->lgas['A1b'])->inactive()->create();

    expect($actor->can('viewAny', User::class))->toBe($expected['viewAny'])
        ->and($actor->can('view', $this->inJurisdiction))->toBe($expected['view_in'])
        ->and($actor->can('view', $this->outOfJurisdiction))->toBe($expected['view_out'])
        ->and($actor->can('create', User::class))->toBe($expected['manage'])
        ->and($actor->can('update', $this->inJurisdiction))->toBe($expected['manage'])
        ->and($actor->can('update', $this->outOfJurisdiction))->toBe($expected['manage'])
        ->and($actor->can('changeRole', $this->inJurisdiction))->toBe($expected['manage'])
        ->and($actor->can('resetTwoFactor', $this->outOfJurisdiction))->toBe($expected['manage'])
        ->and($actor->can('deactivate', $this->outOfJurisdiction))->toBe($expected['manage'])
        ->and($actor->can('reactivate', $inactiveTarget))->toBe($expected['manage']);
})->with('actors');

test('a user in another state is denied, even with users.view', function () {
    $otherStateDirector = User::factory()->stateDirector(State::factory()->create())->create();

    expect($otherStateDirector->can('view', $this->inJurisdiction))->toBeFalse()
        ->and($this->world->users['state_director']->can('view', $otherStateDirector))->toBeFalse();
});

test('a user cannot deactivate themselves', function () {
    $admin = $this->world->users['super_admin'];
    User::factory()->superAdmin()->create(); // not the last super admin

    expect($admin->can('deactivate', $admin))->toBeFalse();
});

test('an active user cannot be reactivated and an inactive one cannot be deactivated', function () {
    $admin = $this->world->users['super_admin'];
    $inactive = User::factory()->directorGeneral()->inactive()->create();

    expect($admin->can('reactivate', $this->inJurisdiction))->toBeFalse()
        ->and($admin->can('deactivate', $inactive))->toBeFalse();
});

test('the last active super admin cannot be deactivated or have their role changed', function () {
    $last = $this->world->users['super_admin'];
    $other = User::factory()->superAdmin()->create();

    // Two active super admins: each may act on the other.
    expect($last->can('deactivate', $other))->toBeTrue()
        ->and($last->can('changeRole', $other))->toBeTrue();

    $other->forceFill(['is_active' => false])->save();

    // An inactive super admin does not count.
    expect($other->can('deactivate', $last))->toBeFalse()
        ->and($last->can('changeRole', $last))->toBeFalse();

    $second = User::factory()->superAdmin()->create();

    expect($second->can('deactivate', $last))->toBeTrue()
        ->and($second->can('changeRole', $last))->toBeTrue();
});

test('the super admin role check ignores the jurisdiction context', function () {
    app(JurisdictionContext::class)->none();

    expect(app(SuperAdmins::class)->activeCount())->toBe(1)
        ->and($this->world->users['super_admin']->hasBuffaloRole(Role::SuperAdmin))->toBeTrue();
});

test('Horizon is only for users with system.horizon, in every environment', function () {
    app()->detectEnvironment(fn () => 'local');

    $this->get('/horizon')->assertForbidden();
    $this->actingAs($this->world->users['director_general'])->get('/horizon')->assertForbidden();
    $this->actingAs($this->world->users['super_admin'])->get('/horizon')->assertOk();

    // A locked account is signed out before it reaches Horizon.
    $lockedAdmin = User::factory()->superAdmin()->locked()->create();
    $this->actingAs($lockedAdmin)->get('/horizon')->assertRedirect(route('login'));
    $this->assertGuest();
});
