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
    scope: { label: string; zones: string[]; states: number; lgas: number };
    map: MapView;
    placeholders: PlaceholderCardData[];
};

export type StateDashboardProps = {
    state: { ulid: string; name: string; label: string; zone: string | null };
    lgas: {
        ulid: string;
        name: string;
        headquarters: string | null;
        chief: { ulid: string; name: string } | null;
    }[];
    map: MapView;
    placeholders: PlaceholderCardData[];
};

export type LgaDashboardProps = {
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
    map: MapView;
    placeholders: PlaceholderCardData[];
};
