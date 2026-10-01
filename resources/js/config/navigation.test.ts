import { describe, expect, it } from 'vitest';
import { NAVIGATION, visibleNavigation } from '@/config/navigation';
import type { NavSection } from '@/config/navigation';
import { PERMISSIONS } from '@/types/auth';
import type { Permission } from '@/types/auth';

function titles(sections: NavSection[]): Record<string, string[]> {
    return Object.fromEntries(
        sections.map((section) => [
            section.title,
            section.items.map((item) => item.title),
        ]),
    );
}

// Permissions per role, as granted by RolesAndPermissionsSeeder.
const ROLE_PERMISSIONS: Record<string, Permission[]> = {
    super_admin: [
        'users.view',
        'users.manage',
        'audit.view',
        'login_history.view',
        'system.horizon',
        'system.settings',
        'reference_data.view',
        'reference_data.manage',
    ],
    director_general: [
        'users.view',
        'audit.view',
        'reference_data.view',
        'reference_data.manage',
        'lgas.view',
        'lga_profiles.update',
        'communities.view',
        'communities.manage',
        'communities.import',
        'communities.delete',
        'religious_institutions.view',
        'religious_institutions.manage',
        'vulnerable_stats.view',
        'vulnerable_stats.manage',
        'security_notes.view',
        'security_notes.manage',
        'religious_institutions.delete',
        'vulnerable_stats.delete',
        'security_notes.delete',
        'persons.view',
        'persons.manage',
        'persons.delete',
        'political.view',
        'political.manage',
        'political.delete',
        'poi.view',
        'poi.manage',
        'poi.clear',
        'poi.delete',
        'criminal_groups.view',
        'criminal_groups.manage',
        'criminal_groups.delete',
        'access_requests.oversee',
        'formations.view',
        'formations.manage',
        'formations.delete',
        'coverage.view',
        'organizations.view',
        'organizations.manage',
        'organizations.delete',
        'organization_sites.delete',
        'incidents.view',
        'incidents.create',
        'incidents.update',
        'evidence.upload',
        'evidence.download_original',
        'evidence.verify',
        'evidence.delete',
        'incidents.submit',
        'incidents.investigate',
        'incidents.close',
        'incidents.invalidate',
        'incidents.acknowledge',
        'incidents.amend',
    ],
    deputy_director_general: [
        'users.view',
        'reference_data.view',
        'lgas.view',
        'lga_profiles.update',
        'communities.view',
        'communities.manage',
        'communities.delete',
        'religious_institutions.view',
        'religious_institutions.manage',
        'vulnerable_stats.view',
        'vulnerable_stats.manage',
        'security_notes.view',
        'security_notes.manage',
        'religious_institutions.delete',
        'vulnerable_stats.delete',
        'security_notes.delete',
        'persons.view',
        'persons.manage',
        'persons.delete',
        'political.view',
        'political.manage',
        'political.delete',
        'poi.view',
        'poi.manage',
        'poi.clear',
        'poi.delete',
        'criminal_groups.view',
        'criminal_groups.manage',
        'criminal_groups.delete',
        'access_requests.oversee',
        'formations.view',
        'formations.manage',
        'formations.delete',
        'coverage.view',
        'organizations.view',
        'organizations.manage',
        'organizations.delete',
        'organization_sites.delete',
        'incidents.view',
        'incidents.create',
        'incidents.update',
        'evidence.upload',
        'evidence.download_original',
        'evidence.verify',
        'evidence.delete',
        'incidents.submit',
        'incidents.investigate',
        'incidents.close',
        'incidents.invalidate',
        'incidents.acknowledge',
        'incidents.amend',
    ],
    state_director: [
        'users.view',
        'reference_data.view',
        'lgas.view',
        'lga_profiles.update',
        'communities.view',
        'communities.manage',
        'communities.import',
        'communities.delete',
        'religious_institutions.view',
        'religious_institutions.manage',
        'vulnerable_stats.view',
        'vulnerable_stats.manage',
        'security_notes.view',
        'security_notes.manage',
        'religious_institutions.delete',
        'vulnerable_stats.delete',
        'security_notes.delete',
        'persons.view',
        'persons.manage',
        'persons.delete',
        'political.view',
        'political.manage',
        'political.delete',
        'poi.view',
        'poi.manage',
        'poi.clear',
        'poi.delete',
        'criminal_groups.view',
        'criminal_groups.manage',
        'criminal_groups.delete',
        'access_requests.create',
        'access_requests.decide',
        'delegations.manage',
        'formations.view',
        'formations.manage',
        'formations.delete',
        'coverage.view',
        'coverage.create',
        'coverage.review',
        'organizations.view',
        'organizations.manage',
        'organization_sites.delete',
        'incidents.view',
        'incidents.create',
        'incidents.update',
        'evidence.upload',
        'evidence.download_original',
        'evidence.verify',
        'evidence.delete',
        'incidents.submit',
        'incidents.verify',
        'incidents.investigate',
        'incidents.close',
        'incidents.invalidate',
        'incidents.escalate',
        'incidents.amend',
    ],
    deputy_state_director: [
        'users.view',
        'reference_data.view',
        'lgas.view',
        'lga_profiles.update',
        'communities.view',
        'communities.manage',
        'communities.import',
        'religious_institutions.view',
        'religious_institutions.manage',
        'vulnerable_stats.view',
        'vulnerable_stats.manage',
        'security_notes.view',
        'security_notes.manage',
        'persons.view',
        'persons.manage',
        'political.view',
        'political.manage',
        'poi.view',
        'poi.manage',
        'criminal_groups.view',
        'criminal_groups.manage',
        'access_requests.create',
        'access_requests.decide',
        'formations.view',
        'formations.manage',
        'coverage.view',
        'coverage.create',
        'coverage.review',
        'organizations.view',
        'organizations.manage',
        'incidents.view',
        'incidents.create',
        'incidents.update',
        'evidence.upload',
        'evidence.download_original',
        'incidents.submit',
        'incidents.verify',
        'incidents.investigate',
        'incidents.escalate',
        'incidents.amend',
    ],
    lga_security_chief: [
        'reference_data.view',
        'lgas.view',
        'lga_profiles.update',
        'communities.view',
        'communities.manage',
        'religious_institutions.view',
        'religious_institutions.manage',
        'vulnerable_stats.view',
        'vulnerable_stats.manage',
        'security_notes.view',
        'security_notes.manage',
        'persons.view',
        'persons.manage',
        'political.view',
        'political.manage',
        'poi.view',
        'poi.manage',
        'criminal_groups.view',
        'criminal_groups.manage',
        'access_requests.create',
        'formations.view',
        'formations.manage',
        'coverage.view',
        'coverage.create',
        'organizations.view',
        'organizations.manage',
        'incidents.view',
        'incidents.create',
        'incidents.update',
        'evidence.upload',
        'evidence.download_original',
        'incidents.submit',
    ],
};

describe('navigation', () => {
    it('shows only the dashboard without permissions', () => {
        expect(titles(visibleNavigation([]))).toEqual({
            Overview: ['Dashboard'],
        });
    });

    it('shows each item only with its permission', () => {
        for (const section of NAVIGATION) {
            for (const item of section.items) {
                if (item.permission === null) {
                    continue;
                }

                // A list: any one of them shows the item; none hides it.
                const required: readonly Permission[] = Array.isArray(
                    item.permission,
                )
                    ? item.permission
                    : [item.permission as Permission];
                const others = PERMISSIONS.filter(
                    (permission) => !required.includes(permission),
                );

                expect(
                    titles(visibleNavigation(others))[section.title] ?? [],
                ).not.toContain(item.title);

                for (const permission of required) {
                    expect(
                        titles(visibleNavigation([permission]))[section.title],
                    ).toContain(item.title);
                }
            }
        }
    });

    it('matches each role', () => {
        const byRole = Object.fromEntries(
            Object.entries(ROLE_PERMISSIONS).map(([role, permissions]) => [
                role,
                titles(visibleNavigation(permissions)),
            ]),
        );

        expect(byRole).toEqual({
            super_admin: {
                Overview: ['Dashboard'],
                Administration: [
                    'Users',
                    'Reference data',
                    'Audit log',
                    'Login history',
                    'Queue monitor',
                ],
            },
            director_general: {
                Overview: ['Dashboard'],
                Incidents: ['Report incident', 'Incidents', 'Incident map'],
                'Local Government': [
                    'LGAs',
                    'Communities',
                    'Senatorial districts',
                ],
                Intelligence: [
                    'Persons',
                    'Persons of interest',
                    'Criminal groups',
                    'Access requests',
                ],
                Security: [
                    'Agency formations',
                    'Coverage reports',
                    'Organizations',
                ],
                Administration: ['Users', 'Reference data', 'Audit log'],
            },
            deputy_director_general: {
                Overview: ['Dashboard'],
                Incidents: ['Report incident', 'Incidents', 'Incident map'],
                'Local Government': [
                    'LGAs',
                    'Communities',
                    'Senatorial districts',
                ],
                Intelligence: [
                    'Persons',
                    'Persons of interest',
                    'Criminal groups',
                    'Access requests',
                ],
                Security: [
                    'Agency formations',
                    'Coverage reports',
                    'Organizations',
                ],
                Administration: ['Users', 'Reference data'],
            },
            state_director: {
                Overview: ['Dashboard'],
                Incidents: ['Report incident', 'Incidents', 'Incident map'],
                'Local Government': [
                    'LGAs',
                    'Communities',
                    'Senatorial districts',
                ],
                Intelligence: [
                    'Persons',
                    'Persons of interest',
                    'Criminal groups',
                    'Access requests',
                ],
                Security: [
                    'Agency formations',
                    'Coverage reports',
                    'Organizations',
                ],
                Administration: ['Users', 'Reference data'],
            },
            deputy_state_director: {
                Overview: ['Dashboard'],
                Incidents: ['Report incident', 'Incidents', 'Incident map'],
                'Local Government': [
                    'LGAs',
                    'Communities',
                    'Senatorial districts',
                ],
                Intelligence: [
                    'Persons',
                    'Persons of interest',
                    'Criminal groups',
                    'Access requests',
                ],
                Security: [
                    'Agency formations',
                    'Coverage reports',
                    'Organizations',
                ],
                Administration: ['Users', 'Reference data'],
            },
            lga_security_chief: {
                Overview: ['Dashboard'],
                Incidents: ['Report incident', 'Incidents', 'Incident map'],
                'Local Government': [
                    'LGAs',
                    'Communities',
                    'Senatorial districts',
                ],
                Intelligence: [
                    'Persons',
                    'Persons of interest',
                    'Criminal groups',
                    'Access requests',
                ],
                Security: [
                    'Agency formations',
                    'Coverage reports',
                    'Organizations',
                ],
                Administration: ['Reference data'],
            },
        });
    });

    it('opens the queue monitor in a new tab', () => {
        const horizon = NAVIGATION.flatMap((section) => section.items).find(
            (item) => item.permission === 'system.horizon',
        );

        expect(horizon?.external).toBe(true);
    });

    it('uses only known permissions', () => {
        for (const item of NAVIGATION.flatMap((section) => section.items)) {
            const listed: readonly Permission[] =
                item.permission === null
                    ? []
                    : Array.isArray(item.permission)
                      ? item.permission
                      : [item.permission as Permission];

            for (const permission of listed) {
                expect(PERMISSIONS).toContain(permission);
            }
        }
    });
});
