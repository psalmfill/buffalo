<?php

use App\Actions\Users\AssignRole;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;

test('roles hold exactly the permissions from the specification', function () {
    // Written out independently of the seeder, from the Phase 1B and 2A prompts.
    $expected = [
        'super_admin' => ['audit.view', 'login_history.view', 'reference_data.manage', 'reference_data.view', 'system.horizon', 'system.settings', 'users.manage', 'users.view'],
        'director_general' => ['audit.view', 'reference_data.manage', 'reference_data.view', 'users.view'],
        'deputy_director_general' => ['reference_data.view', 'users.view'],
        'state_director' => ['reference_data.view', 'users.view'],
        'deputy_state_director' => ['reference_data.view', 'users.view'],
        'lga_security_chief' => ['reference_data.view'],
    ];

    $actual = SpatieRole::query()->with('permissions')->get()
        ->mapWithKeys(fn (SpatieRole $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->sortKeys()
        ->all();

    expect($actual)->toEqual(collect($expected)->sortKeys()->all());
});

test('every role and permission enum case exists, with the web guard', function () {
    expect(SpatieRole::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toEqual(collect(Role::cases())->map->value->sort()->values()->all())
        ->and(SpatiePermission::query()->where('guard_name', 'web')->pluck('name')->sort()->values()->all())
        ->toEqual(collect(Permission::cases())->map->value->sort()->values()->all());
});

test('every permission is granted to at least one role', function () {
    $granted = collect(RolesAndPermissionsSeeder::permissionsByRole())->flatten()->unique()->sort()->values()->all();

    expect($granted)->toEqual(collect(Permission::cases())->map->value->sort()->values()->all());
});

test('the seeder is idempotent and restores drifted permissions', function () {
    SpatieRole::findByName('lga_security_chief')->givePermissionTo('users.manage');

    $this->seed(RolesAndPermissionsSeeder::class);

    expect(SpatieRole::count())->toBe(6)
        ->and(SpatiePermission::count())->toBe(count(Permission::cases()))
        ->and(SpatieRole::findByName('lga_security_chief')->permissions->pluck('name')->all())->toBe(['reference_data.view']);
});

test('AssignRole leaves a user with exactly one role', function () {
    $user = User::factory()->create();
    $assign = app(AssignRole::class);

    $assign($user, Role::StateDirector);
    $assign($user, Role::StateDirector);
    $assign($user, Role::DirectorGeneral);

    expect($user->fresh()->roles->pluck('name')->all())->toBe(['director_general'])
        ->and($user->getRole())->toBe(Role::DirectorGeneral);
});

test('the database rejects a second role for the same user', function () {
    $user = User::factory()->directorGeneral()->create();

    expect(fn () => DB::table('model_has_roles')->insert([
        'role_id' => SpatieRole::findByName('super_admin')->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
    ]))->toThrow(QueryException::class, 'model_has_roles_one_role_per_model_unique');
});

test('a user with no role has no permissions', function () {
    $user = User::factory()->create();

    expect($user->getRole())->toBeNull()
        ->and($user->getAllPermissions())->toHaveCount(0);
});
