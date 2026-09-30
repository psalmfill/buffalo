import type { AccessFiguresData } from '@/components/dashboard/access-figures';
import type {
    LgaCoverageData,
    NationalCoverageData,
    StateCoverageData,
} from '@/components/dashboard/coverage-figures';
import type {
    LgaOrganizationData,
    NationalOrganizationData,
    StateOrganizationData,
} from '@/components/dashboard/organization-figures';
import type {
    PoiPlaceRow,
    RiskCount,
} from '@/components/dashboard/threat-figures';
import type { Option } from '@/types/local-government';
import type { RoleName } from '@/types/auth';
import type { ActivityEntry } from '@/types/users';

export type MapView = {
    /** [lat, lng]; null means the national view. */
    center: [number, number] | null;
    zoom: number;
};

export type PlaceholderCardData = {
    key: string;
    title: string;
    scope: string;
    availableFromPhase: number;
};

export type QueueStatus = 'running' | 'paused' | 'inactive';

export type SystemDashboardProps = {
    usersByRole: { role: RoleName | null; label: string; count: number }[];
    usersByStatus: {
        active: number;
        inactive: number;
        locked: number;
        setup_pending: number;
    };
    failedLogins24h: number;
    lockouts7d: number;
    recentAudit: ActivityEntry[] | null;
    queueStatus: QueueStatus;
};

export type NationalDashboardProps = {
    access: AccessFiguresData | null;
    coverage: NationalCoverageData | null;
    organizations: NationalOrganizationData | null;
    scope: { label: string; zones: string[]; states: number; lgas: number };
    completenessByState: { state: string; lgas: number; average: number }[];
    threats: {
        riskLevels: Option[];
        poisByState: PoiPlaceRow[];
        groupsByType: RiskCount[];
    };
    securityNotesByState: {
        categories: { value: string; label: string }[];
        rows: {
            state: string;
            total: number;
            byCategory: Record<string, number>;
        }[];
    };
    map: MapView;
    placeholders: PlaceholderCardData[];
};

export type StateDashboardProps = {
    access: AccessFiguresData | null;
    coverage: StateCoverageData | null;
    organizations: StateOrganizationData | null;
    state: { ulid: string; name: string; label: string; zone: string | null };
    lgas: {
        ulid: string;
        name: string;
        headquarters: string | null;
        chief: { ulid: string; name: string } | null;
        completeness: number;
        last_reviewed_at: string | null;
    }[];
    securityNotesByLga: { ulid: string; name: string; active: number }[];
    threats: {
        riskLevels: Option[];
        poisByLga: PoiPlaceRow[];
        overdueReviews: number;
        activeGroups: number;
    };
    vulnerableTotals: {
        category: string;
        label: string;
        adult_male: number;
        adult_female: number;
        child_male: number;
        child_female: number;
        total: number;
    }[];
    map: MapView;
    placeholders: PlaceholderCardData[];
};

export type LgaDashboardProps = {
    access: AccessFiguresData | null;
    coverage: LgaCoverageData | null;
    organizations: LgaOrganizationData | null;
    lga: {
        ulid: string;
        name: string;
        label: string;
        state: string;
        senatorialDistrict: string | null;
        headquarters: string | null;
        areaKm2: string | null;
        populationEstimate: number | null;
    };
    information: { activeSecurityNotes: number; religiousInstitutions: number };
    threats: { poisByRisk: RiskCount[]; overdueReviews: number } | null;
    profile: {
        completeness: number;
        missing: string[];
        communities: number;
        last_reviewed_at: string | null;
    };
    map: MapView;
    placeholders: PlaceholderCardData[];
};
