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
    ],
    deputy_director_general: ['users.view', 'reference_data.view'],
    state_director: ['users.view', 'reference_data.view'],
    deputy_state_director: ['users.view', 'reference_data.view'],
    lga_security_chief: ['reference_data.view'],
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

                const others = PERMISSIONS.filter(
                    (permission) => permission !== item.permission,
                );

                expect(
                    titles(visibleNavigation(others))[section.title] ?? [],
                ).not.toContain(item.title);
                expect(
                    titles(visibleNavigation([item.permission]))[section.title],
                ).toContain(item.title);
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
                Administration: ['Users', 'Reference data', 'Audit log'],
            },
            deputy_director_general: {
                Overview: ['Dashboard'],
                Administration: ['Users', 'Reference data'],
            },
            state_director: {
                Overview: ['Dashboard'],
                Administration: ['Users', 'Reference data'],
            },
            deputy_state_director: {
                Overview: ['Dashboard'],
                Administration: ['Users', 'Reference data'],
            },
            lga_security_chief: {
                Overview: ['Dashboard'],
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
            if (item.permission !== null) {
                expect(PERMISSIONS).toContain(item.permission);
            }
        }
    });
});
