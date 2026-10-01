import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Building2,
    ClipboardCheck,
    ClipboardList,
    FilePlus2,
    Flame,
    Crosshair,
    KeyRound,
    Gauge,
    History,
    LayoutGrid,
    Landmark,
    Library,
    Map as MapIcon,
    MapPin,
    MapPinned,
    Network,
    ScrollText,
    Shield,
    UserSearch,
    Users,
} from 'lucide-react';
import AccessRequestController from '@/actions/App/Http/Controllers/AccessRequests/AccessRequestController';
import FlashpointController from '@/actions/App/Http/Controllers/Flashpoints/FlashpointController';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import AgencyFormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import OrganizationController from '@/actions/App/Http/Controllers/Organizations/OrganizationController';
import CoverageReportController from '@/actions/App/Http/Controllers/Security/CoverageReportController';
import AuditLogController from '@/actions/App/Http/Controllers/Audit/AuditLogController';
import CriminalGroupController from '@/actions/App/Http/Controllers/CriminalGroups/CriminalGroupController';
import CommunityController from '@/actions/App/Http/Controllers/LocalGovernment/CommunityController';
import LgaController from '@/actions/App/Http/Controllers/LocalGovernment/LgaController';
import LoginHistoryController from '@/actions/App/Http/Controllers/Audit/LoginHistoryController';
import PersonController from '@/actions/App/Http/Controllers/Persons/PersonController';
import PersonOfInterestController from '@/actions/App/Http/Controllers/PersonsOfInterest/PersonOfInterestController';
import SenatorialDistrictController from '@/actions/App/Http/Controllers/Political/SenatorialDistrictController';
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
    /** null: everyone; a list: any one of them. */
    permission: Permission | readonly Permission[] | null;
    /** Not an Inertia page: opens in a new tab (e.g. Horizon). */
    external?: boolean;
    /** A shared count shown next to the item (`badges` page prop). */
    badge?: 'coverageReview';
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
        title: 'Incidents',
        items: [
            { title: 'Report incident', icon: FilePlus2, href: IncidentController.create(), permission: 'incidents.create' },
            { title: 'Incidents', icon: ClipboardList, href: IncidentListController.index(), permission: 'incidents.view' },
            { title: 'Incident map', icon: MapPin, href: IncidentListController.map(), permission: 'incidents.view' },
        ],
    },
    {
        title: 'Flashpoints',
        items: [
            { title: 'Flashpoints & blackspots', icon: Flame, href: FlashpointController.index(), permission: 'flashpoints.view' },
        ],
    },
    {
        title: 'Local Government',
        items: [
            { title: 'LGAs', icon: Landmark, href: LgaController.index(), permission: 'lgas.view' },
            { title: 'Communities', icon: MapPinned, href: CommunityController.index(), permission: 'communities.view' },
            { title: 'Senatorial districts', icon: MapIcon, href: SenatorialDistrictController.index(), permission: 'lgas.view' },
        ],
    },
    {
        title: 'Intelligence',
        items: [
            { title: 'Persons', icon: UserSearch, href: PersonController.index(), permission: 'persons.view' },
            { title: 'Persons of interest', icon: Crosshair, href: PersonOfInterestController.index(), permission: 'poi.view' },
            { title: 'Criminal groups', icon: Network, href: CriminalGroupController.index(), permission: 'criminal_groups.view' },
            { title: 'Access requests', icon: KeyRound, href: AccessRequestController.index(), permission: ['access_requests.create', 'access_requests.decide', 'access_requests.oversee'] },
        ],
    },
    {
        title: 'Security',
        items: [
            { title: 'Agency formations', icon: Shield, href: AgencyFormationController.index(), permission: 'formations.view' },
            { title: 'Coverage reports', icon: ClipboardCheck, href: CoverageReportController.index(), permission: 'coverage.view', badge: 'coverageReview' },
            { title: 'Organizations', icon: Building2, href: OrganizationController.index(), permission: 'organizations.view' },
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
                Array.isArray(item.permission)
                    ? item.permission.some((p) => can(permissions, p))
                    : can(permissions, item.permission as Permission | null),
            ),
        }))
        .filter((section) => section.items.length > 0);
}
