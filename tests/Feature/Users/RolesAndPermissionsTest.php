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
    // Written out independently of the seeder, from the Phase 1B, 2A, 2B and 2C prompts.
    $expected = [
        'super_admin' => ['audit.view', 'login_history.view', 'reference_data.manage', 'reference_data.view', 'system.horizon', 'system.settings', 'users.manage', 'users.view'],
        'director_general' => ['access_requests.oversee', 'audit.view', 'communities.delete', 'communities.import', 'communities.manage', 'communities.view', 'coverage.view', 'criminal_groups.delete', 'criminal_groups.manage', 'criminal_groups.view', 'evidence.delete', 'evidence.download_original', 'evidence.upload', 'evidence.verify', 'flashpoints.assess', 'flashpoints.delete', 'flashpoints.manage', 'flashpoints.view', 'formations.delete', 'formations.manage', 'formations.view', 'incidents.acknowledge', 'incidents.amend', 'incidents.close', 'incidents.create', 'incidents.invalidate', 'incidents.investigate', 'incidents.submit', 'incidents.update', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organization_sites.delete', 'organizations.delete', 'organizations.manage', 'organizations.view', 'persons.delete', 'persons.manage', 'persons.view', 'poi.clear', 'poi.delete', 'poi.manage', 'poi.view', 'political.delete', 'political.manage', 'political.view', 'reference_data.manage', 'reference_data.view', 'religious_institutions.delete', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.delete', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.delete', 'threats.manage', 'threats.reactivate', 'threats.view', 'users.view', 'vulnerable_stats.delete', 'vulnerable_stats.manage', 'vulnerable_stats.view'],
        'deputy_director_general' => ['access_requests.oversee', 'communities.delete', 'communities.manage', 'communities.view', 'coverage.view', 'criminal_groups.delete', 'criminal_groups.manage', 'criminal_groups.view', 'evidence.delete', 'evidence.download_original', 'evidence.upload', 'evidence.verify', 'flashpoints.assess', 'flashpoints.delete', 'flashpoints.manage', 'flashpoints.view', 'formations.delete', 'formations.manage', 'formations.view', 'incidents.acknowledge', 'incidents.amend', 'incidents.close', 'incidents.create', 'incidents.invalidate', 'incidents.investigate', 'incidents.submit', 'incidents.update', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organization_sites.delete', 'organizations.delete', 'organizations.manage', 'organizations.view', 'persons.delete', 'persons.manage', 'persons.view', 'poi.clear', 'poi.delete', 'poi.manage', 'poi.view', 'political.delete', 'political.manage', 'political.view', 'reference_data.view', 'religious_institutions.delete', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.delete', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.delete', 'threats.manage', 'threats.reactivate', 'threats.view', 'users.view', 'vulnerable_stats.delete', 'vulnerable_stats.manage', 'vulnerable_stats.view'],
        'state_director' => ['access_requests.create', 'access_requests.decide', 'communities.delete', 'communities.import', 'communities.manage', 'communities.view', 'coverage.create', 'coverage.review', 'coverage.view', 'criminal_groups.delete', 'criminal_groups.manage', 'criminal_groups.view', 'delegations.manage', 'evidence.delete', 'evidence.download_original', 'evidence.upload', 'evidence.verify', 'flashpoints.assess', 'flashpoints.delete', 'flashpoints.manage', 'flashpoints.view', 'formations.delete', 'formations.manage', 'formations.view', 'incidents.amend', 'incidents.close', 'incidents.create', 'incidents.escalate', 'incidents.invalidate', 'incidents.investigate', 'incidents.submit', 'incidents.update', 'incidents.verify', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organization_sites.delete', 'organizations.manage', 'organizations.view', 'persons.delete', 'persons.manage', 'persons.view', 'poi.clear', 'poi.delete', 'poi.manage', 'poi.view', 'political.delete', 'political.manage', 'political.view', 'reference_data.view', 'religious_institutions.delete', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.delete', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.delete', 'threats.manage', 'threats.reactivate', 'threats.view', 'users.view', 'vulnerable_stats.delete', 'vulnerable_stats.manage', 'vulnerable_stats.view'],
        'deputy_state_director' => ['access_requests.create', 'access_requests.decide', 'communities.import', 'communities.manage', 'communities.view', 'coverage.create', 'coverage.review', 'coverage.view', 'criminal_groups.manage', 'criminal_groups.view', 'evidence.download_original', 'evidence.upload', 'flashpoints.assess', 'flashpoints.manage', 'flashpoints.view', 'formations.manage', 'formations.view', 'incidents.amend', 'incidents.create', 'incidents.escalate', 'incidents.investigate', 'incidents.submit', 'incidents.update', 'incidents.verify', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organizations.manage', 'organizations.view', 'persons.manage', 'persons.view', 'poi.manage', 'poi.view', 'political.manage', 'political.view', 'reference_data.view', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.manage', 'threats.view', 'users.view', 'vulnerable_stats.manage', 'vulnerable_stats.view'],
        'lga_security_chief' => ['access_requests.create', 'communities.manage', 'communities.view', 'coverage.create', 'coverage.view', 'criminal_groups.manage', 'criminal_groups.view', 'evidence.download_original', 'evidence.upload', 'flashpoints.assess', 'flashpoints.manage', 'flashpoints.view', 'formations.manage', 'formations.view', 'incidents.create', 'incidents.submit', 'incidents.update', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organizations.manage', 'organizations.view', 'persons.manage', 'persons.view', 'poi.manage', 'poi.view', 'political.manage', 'political.view', 'reference_data.view', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.manage', 'threats.view', 'vulnerable_stats.manage', 'vulnerable_stats.view'],
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
        ->and(SpatieRole::findByName('lga_security_chief')->permissions->pluck('name')->sort()->values()->all())->toBe(['access_requests.create', 'communities.manage', 'communities.view', 'coverage.create', 'coverage.view', 'criminal_groups.manage', 'criminal_groups.view', 'evidence.download_original', 'evidence.upload', 'flashpoints.assess', 'flashpoints.manage', 'flashpoints.view', 'formations.manage', 'formations.view', 'incidents.create', 'incidents.submit', 'incidents.update', 'incidents.view', 'lga_profiles.update', 'lgas.view', 'organizations.manage', 'organizations.view', 'persons.manage', 'persons.view', 'poi.manage', 'poi.view', 'political.manage', 'political.view', 'reference_data.view', 'religious_institutions.manage', 'religious_institutions.view', 'security_notes.manage', 'security_notes.view', 'threats.create', 'threats.manage', 'threats.view', 'vulnerable_stats.manage', 'vulnerable_stats.view']);
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
