<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the six roles and all permissions, then syncs each role's
 * permissions to exactly what is listed here. Idempotent and safe in
 * production (it never touches users).
 *
 * To add permissions in a later phase: add cases to App\Enums\Permission and
 * a new clearly labelled module array in matrix().
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    /**
     * Module => [permission => roles that hold it].
     *
     * Permissions only decide what a user may do. Which records they may do
     * it to is decided by jurisdiction (HasJurisdiction + policies): e.g.
     * users.view for a state director means users in their state.
     *
     * @return array<string, array<string, list<Role>>>
     */
    public static function matrix(): array
    {
        return [
            // ---------------------------------------------------------------
            // Phase 1 — Users
            // ---------------------------------------------------------------
            'users' => [
                Permission::UsersView->value => [
                    Role::SuperAdmin,
                    Role::DirectorGeneral,
                    Role::DeputyDirectorGeneral,
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                ],
                Permission::UsersManage->value => [
                    Role::SuperAdmin,
                ],
            ],

            // ---------------------------------------------------------------
            // Phase 1 — Audit
            // ---------------------------------------------------------------
            'audit' => [
                Permission::AuditView->value => [
                    Role::SuperAdmin,
                    Role::DirectorGeneral,
                ],
                Permission::LoginHistoryView->value => [
                    Role::SuperAdmin,
                ],
            ],

            // ---------------------------------------------------------------
            // Phase 1 — System administration
            // ---------------------------------------------------------------
            'system' => [
                Permission::SystemHorizon->value => [
                    Role::SuperAdmin,
                ],
                Permission::SystemSettings->value => [
                    Role::SuperAdmin,
                ],
            ],
        ];
    }

    /**
     * Role => permission names, derived from matrix().
     *
     * @return array<string, list<string>>
     */
    public static function permissionsByRole(): array
    {
        $byRole = array_fill_keys(array_map(fn (Role $role): string => $role->value, Role::cases()), []);

        foreach (self::matrix() as $permissions) {
            foreach ($permissions as $permission => $roles) {
                foreach ($roles as $role) {
                    $byRole[$role->value][] = $permission;
                }
            }
        }

        return $byRole;
    }

    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        DB::transaction(function (): void {
            foreach (Permission::cases() as $permission) {
                SpatiePermission::findOrCreate($permission->value, self::GUARD);
            }

            foreach (self::permissionsByRole() as $role => $permissions) {
                SpatieRole::findOrCreate($role, self::GUARD)->syncPermissions($permissions);
            }
        });

        $registrar->forgetCachedPermissions();

        $this->command->info(sprintf('Synced %d roles and %d permissions.', count(Role::cases()), count(Permission::cases())));
    }
}
