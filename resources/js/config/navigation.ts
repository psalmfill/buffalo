import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Gauge,
    History,
    LayoutGrid,
    Library,
    ScrollText,
    Users,
} from 'lucide-react';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import LoginHistoryController from '@/actions/App/Http/Controllers/Audit/LoginHistoryController';
import ReferenceDataController from '@/actions/App/Http/Controllers/ReferenceData/ReferenceDataController';
import UserController from '@/actions/App/Http/Controllers/Users/UserController';
import { can } from '@/lib/permissions';
import { dashboard } from '@/routes';
import horizon from '@/routes/horizon';
import type { Permission } from '@/types/auth';

/**
 * The sidebar, as data. Each item names the permission that shows it (null:
 * everyone). Showing or hiding an item is never a security control: every
 * route is authorised on the server.
 *
 * Later phases add a section (Intelligence, Incidents, Flashpoints, Reports)
 * or an item with one entry here.
 */
export type NavItem = {
    title: string;
    icon: LucideIcon;
    href: NonNullable<InertiaLinkProps['href']>;
    permission: Permission | null;
    /** Not an Inertia page: opens in a new tab (e.g. Horizon). */
    external?: boolean;
};

export type NavSection = {
    title: string;
    items: NavItem[];
};

// One line per item: prettier would spread each item over several lines.
// prettier-ignore
export const NAVIGATION: NavSection[] = [
    {
        title: 'Overview',
        items: [
            { title: 'Dashboard', icon: LayoutGrid, href: dashboard(), permission: null },
        ],
    },
    {
        title: 'Administration',
        items: [
            { title: 'Users', icon: Users, href: UserController.index(), permission: 'users.view' },
            { title: 'Reference data', icon: Library, href: ReferenceDataController.home(), permission: 'reference_data.view' },
            { title: 'Audit log', icon: ScrollText, href: AuditLogController.index(), permission: 'audit.view' },
            { title: 'Login history', icon: History, href: LoginHistoryController.index(), permission: 'login_history.view' },
            { title: 'Queue monitor', icon: Gauge, href: horizon.index(), permission: 'system.horizon', external: true },
        ],
    },
];

/**
 * The sections and items `permissions` may see; sections left empty are
 * dropped.
 */
export function visibleNavigation(
    permissions: readonly Permission[],
    sections: readonly NavSection[] = NAVIGATION,
): NavSection[] {
    return sections
        .map((section) => ({
            ...section,
            items: section.items.filter((item) =>
                can(permissions, item.permission),
            ),
        }))
        .filter((section) => section.items.length > 0);
}
