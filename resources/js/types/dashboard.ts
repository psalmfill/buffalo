import type {
    LgaArrestFigures,
    StateArrestFigures,
} from '@/components/dashboard/arrest-figures';
import type { OnboardingRow } from '@/types/rollout';
import type {
    LgaThreatFigures,
    NationalThreatFigures,
    StateThreatFigures,
} from '@/types/threats';
import type { Geometry } from 'geojson';
import type { IncidentListRow } from '@/types/incidents';
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
import type { CrossBorderItem, SharedItem } from '@/types/disseminations';
import type { Option } from '@/types/local-government';
import type { RoleName } from '@/types/auth';
import type { ActivityEntry } from '@/types/users';

export type MapView = {
    /** [lat, lng]; null means the national view. */
    center: [number, number] | null;
    zoom: number;
    /** The place's simplified boundary (GeoJSON), when imported. */
    outline: Geometry | null;
};

export type PlaceholderCardData = {
    key: string;
    title: string;
    scope: string;
    availableFromPhase: number;
    /** Real content once the phase has delivered it (Phase 6: flashpoints). */
    count?: number | null;
    href?: string | null;
};

/** Dashboard period (Phase 7C). */
export type DashboardPeriod = { days: number; options: number[] };

export type KpiTile = {
    key: string;
    label: string;
    value: number | null;
    previous: number | null;
    change_pct: number | null;
    unit: string | null;
    href: string;
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
    onboarding: OnboardingRow[];
    liveStates: number;
    totalStates: number;
};

/** A card of the national state grid (Phase 11A.2); null: not permitted. */
export type StateGridCard = {
    state: { ulid: string; name: string; code: string };
    zone: { ulid: string; name: string } | null;
    incidents7: number | null;
    incidents7Change: number | null;
    incidents30: number | null;
    incidents30Change: number | null;
    criticalOpen: number | null;
    escalationsUnacknowledged: number | null;
    immediateThreats: number | null;
    overdueVerifications: number | null;
    killed30: number | null;
    abducted30: number | null;
    coverageCompliance: number | null;
    /** Phase 11C: arrests in the last 30 days (not in the attention score). */
    arrests30: number | null;
    attention: {
        score: number;
        level: 'critical' | 'high' | 'elevated' | 'none';
    };
};

/** A national user's view of one state or zone (Phase 11A.2). */
export type DashboardPlace = {
    type: 'state' | 'zone';
    ulid: string;
    name: string;
};

export type NationalDashboardProps = {
    stateGrid: StateGridCard[];
    place?: DashboardPlace;
    period: DashboardPeriod;
    kpis: KpiTile[];
    incidents: NationalIncidentFigures | null;
    threatReports: NationalThreatFigures | null;
    crossBorder: { count: number; rows: CrossBorderItem[] } | null;
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
    /** Phase 11C: null without arrests.view. */
    arrests: StateArrestFigures | null;
    place?: DashboardPlace;
    /** Phase 10A: State Directors only; counts only. */
    duplicates: {
        nightly: {
            certain: number;
            strong: number;
            possible: number;
            total: number;
            at: string;
        } | null;
        pendingRequests: number;
    } | null;
    period: DashboardPeriod;
    kpis: KpiTile[];
    incidents: StateIncidentFigures | null;
    threatReports: StateThreatFigures | null;
    sharedWithUs: { count: number; rows: SharedItem[] } | null;
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
    /** Phase 11C: null without arrests.view. */
    arrests: LgaArrestFigures | null;
    period: DashboardPeriod;
    kpis: KpiTile[];
    incidents: LgaIncidentFigures | null;
    threatReports: LgaThreatFigures | null;
    sharedWithUs: { count: number; rows: SharedItem[] } | null;
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

// Incident figures (Phase 5D): drafts and invalid reports are never counted.
type CountRow = { label: string; count: number };

export type LgaIncidentFigures = {
    /** The dashboard period (7, 30 or 90 days, Phase 7C). */
    days: number;
    byType: CountRow[];
    myOpenByStatus: {
        status: { value: string; label: string };
        count: number;
    }[];
    returned: IncidentListRow[];
    drafts: {
        count: number;
        latest: { ulid: string; title: string; updated_at: string | null }[];
    };
    latest: IncidentListRow[];
    channel: string;
};

export type StateIncidentFigures = {
    /** The dashboard period (7, 30 or 90 days, Phase 7C). */
    days: number;
    awaiting: { count: number; overdue: number; rows: IncidentListRow[] };
    criticalHigh: { count: number; rows: IncidentListRow[] };
    byLga: CountRow[];
    byType: CountRow[];
    casualtiesByOutcome: CountRow[];
    escalated: IncidentListRow[];
    channel: string;
};

export type NationalIncidentFigures = {
    /** The dashboard period (7, 30 or 90 days, Phase 7C). */
    days: number;
    escalatedUnacknowledged: { count: number; rows: IncidentListRow[] };
    critical7: { count: number; rows: IncidentListRow[] };
    byState: CountRow[];
    byCategory: CountRow[];
    casualtiesByOutcome: CountRow[];
    casualtiesByGroup: CountRow[];
    securityCasualtiesByAgency: CountRow[];
    statesOverdue: CountRow[];
    channel: string;
};
