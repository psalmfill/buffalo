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

    /** Every role that sees intelligence content (all but super_admin). */
    private const INTELLIGENCE_ROLES = [
        Role::DirectorGeneral,
        Role::DeputyDirectorGeneral,
        Role::StateDirector,
        Role::DeputyStateDirector,
        Role::LgaSecurityChief,
    ];

    /**
     * Who may delete intelligence records (always with a reason): "State
     * Director and above" (not the deputy, not LGA chiefs).
     */
    private const DELETE_ROLES = [
        Role::DirectorGeneral,
        Role::DeputyDirectorGeneral,
        Role::StateDirector,
    ];

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
            // Phase 2A — Reference data (configuration, not intelligence, so
            // every role may read it)
            // ---------------------------------------------------------------
            'reference_data' => [
                Permission::ReferenceDataView->value => [
                    Role::SuperAdmin,
                    Role::DirectorGeneral,
                    Role::DeputyDirectorGeneral,
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                    Role::LgaSecurityChief,
                ],
                Permission::ReferenceDataManage->value => [
                    Role::SuperAdmin,
                    Role::DirectorGeneral,
                ],
            ],

            // ---------------------------------------------------------------
            // Phase 2B — LGA profiles and communities (never the super admin:
            // intelligence content)
            // ---------------------------------------------------------------
            'local_government' => [
                Permission::LgasView->value => self::INTELLIGENCE_ROLES,
                Permission::LgaProfilesUpdate->value => self::INTELLIGENCE_ROLES,
                Permission::CommunitiesView->value => self::INTELLIGENCE_ROLES,
                Permission::CommunitiesManage->value => self::INTELLIGENCE_ROLES,
                Permission::CommunitiesImport->value => [
                    Role::DirectorGeneral,
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                ],
                Permission::CommunitiesDelete->value => self::DELETE_ROLES,
            ],

            // ---------------------------------------------------------------
            // Phase 2C — Community information (never the super admin)
            // ---------------------------------------------------------------
            'community_information' => [
                Permission::ReligiousInstitutionsView->value => self::INTELLIGENCE_ROLES,
                Permission::ReligiousInstitutionsManage->value => self::INTELLIGENCE_ROLES,
                Permission::ReligiousInstitutionsDelete->value => self::DELETE_ROLES,
                Permission::VulnerableStatsView->value => self::INTELLIGENCE_ROLES,
                Permission::VulnerableStatsManage->value => self::INTELLIGENCE_ROLES,
                Permission::VulnerableStatsDelete->value => self::DELETE_ROLES,
                Permission::SecurityNotesView->value => self::INTELLIGENCE_ROLES,
                Permission::SecurityNotesManage->value => self::INTELLIGENCE_ROLES,
                Permission::SecurityNotesDelete->value => self::DELETE_ROLES,
            ],

            // ---------------------------------------------------------------
            // Phase 3A — Persons (never the super admin)
            // ---------------------------------------------------------------
            'persons' => [
                Permission::PersonsView->value => self::INTELLIGENCE_ROLES,
                Permission::PersonsManage->value => self::INTELLIGENCE_ROLES,
                Permission::PersonsDelete->value => self::DELETE_ROLES,
            ],

            // ---------------------------------------------------------------
            // Phase 3B — Political information, persons of interest,
            // criminal groups (never the super admin)
            // ---------------------------------------------------------------
            'political' => [
                Permission::PoliticalView->value => self::INTELLIGENCE_ROLES,
                Permission::PoliticalManage->value => self::INTELLIGENCE_ROLES,
                Permission::PoliticalDelete->value => self::DELETE_ROLES,
            ],
            'persons_of_interest' => [
                Permission::PoiView->value => self::INTELLIGENCE_ROLES,
                Permission::PoiManage->value => self::INTELLIGENCE_ROLES,
                Permission::PoiClear->value => self::DELETE_ROLES,
                Permission::PoiDelete->value => self::DELETE_ROLES,
            ],
            'criminal_groups' => [
                Permission::CriminalGroupsView->value => self::INTELLIGENCE_ROLES,
                Permission::CriminalGroupsManage->value => self::INTELLIGENCE_ROLES,
                Permission::CriminalGroupsDelete->value => self::DELETE_ROLES,
            ],

            // ---------------------------------------------------------------
            // Phase 3C — Cross-jurisdiction access (never the super admin;
            // national users never need requests)
            // ---------------------------------------------------------------
            'cross_jurisdiction' => [
                Permission::AccessRequestsCreate->value => [
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                    Role::LgaSecurityChief,
                ],
                Permission::AccessRequestsDecide->value => [
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                ],
                Permission::AccessRequestsOversee->value => [
                    Role::DirectorGeneral,
                    Role::DeputyDirectorGeneral,
                ],
                Permission::DelegationsManage->value => [
                    Role::StateDirector,
                ],
            ],

            // ---------------------------------------------------------------
            // Phase 4A — Security agencies: formations and coverage reports
            // ---------------------------------------------------------------
            'security' => [
                Permission::FormationsView->value => self::INTELLIGENCE_ROLES,
                Permission::FormationsManage->value => self::INTELLIGENCE_ROLES,
                Permission::FormationsDelete->value => self::DELETE_ROLES,
                Permission::CoverageView->value => self::INTELLIGENCE_ROLES,
                Permission::CoverageCreate->value => [
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                    Role::LgaSecurityChief,
                ],
                Permission::CoverageReview->value => [
                    Role::StateDirector,
                    Role::DeputyStateDirector,
                ],
            ],

            // ---------------------------------------------------------------
            // Phase 4B — Organizations (directory, sites, contacts)
            // ---------------------------------------------------------------
            'organizations' => [
                Permission::OrganizationsView->value => self::INTELLIGENCE_ROLES,
                Permission::OrganizationsManage->value => self::INTELLIGENCE_ROLES,
                Permission::OrganizationsDelete->value => [
                    Role::DirectorGeneral,
                    Role::DeputyDirectorGeneral,
                ],
                Permission::OrganizationSitesDelete->value => self::DELETE_ROLES,
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
