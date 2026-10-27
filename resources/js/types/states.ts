// State pages (Phase 11F). ULIDs and labels only; numeric ids never reach
// the browser.

import type { FeatureCollection } from 'geojson';
import type { MapView } from '@/types/dashboard';
import type { FlashpointRow } from '@/types/flashpoints';
import type { FormationRow } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';
import type { SiteRow } from '@/types/organizations';
import type { PoliticalOffice } from '@/types/political';
import type { ThreatRow } from '@/types/threats';

export type StateRow = {
    ulid: string;
    name: string;
    code: string;
    zone: NamedRef;
    capital: string | null;
    lgas: number;
    director: string | null;
    completeness: number;
    last_reviewed_at: string | null;
    /** Null when the viewer may not see incidents. */
    incidents30: number | null;
    /** Null when the viewer may not see threat reports. */
    immediate_threats: number | null;
};

export type StateFacts = {
    ulid: string;
    name: string;
    code: string;
    zone: NamedRef;
    capital: string | null;
    is_active: boolean;
};

/** Computed from the LGAs; area, population and communities for whole-state viewers only. */
export type StateComputedFacts = {
    lgas: number;
    districts: number;
    area_km2?: number | null;
    area_missing?: number;
    population?: number | null;
    population_missing?: number;
    communities?: number;
};

export type StateOverview = {
    facts: StateComputedFacts;
    officers: { director: NamedRef[]; deputies: NamedRef[] } | null;
    map: MapView;
    lgaOutlines: FeatureCollection | null;
    mapUrl: string | null;
    dashboardUrl: string | null;
};

export type StateProfileData = {
    exists: boolean;
    history: string | null;
    economic_activities: string | null;
    terrain: string[];
    major_roads: string | null;
    waterways: string | null;
    borders: string | null;
    general_notes: string | null;
    last_reviewed_at: string | null;
    last_reviewed_by: string | null;
    terrainOptions: Option[];
};

export type StateLgaRow = {
    ulid: string;
    name: string;
    district: string | null;
    headquarters: string | null;
    chief: NamedRef | null;
    completeness: number;
    incidents30: number | null;
};

export type StateDistrict = {
    ulid: string;
    name: string;
    code: string;
    lgas: NamedRef[];
    senators: NamedRef[];
};

export type StateLeadership = {
    groups: { office: Option; holders: PoliticalOffice[] }[];
    past: PoliticalOffice[];
};

export type Grouped<T> = { value: string; label: string; rows: T[] }[];

export type ReceivedDissemination = {
    ulid: string;
    kind: string;
    label: string;
    href: string;
    from: string;
    to: string;
    disseminated_at: string;
    expires_at: string;
    acknowledged: boolean;
};

export type StateSecurityPresence = {
    formations:
        | {
              agency: { ulid: string; name: string; short_name: string | null };
              formations: FormationRow[];
          }[]
        | null;
    flashpoints: Grouped<FlashpointRow> | null;
    threats: Grouped<ThreatRow> | null;
    disseminations: ReceivedDissemination[] | null;
};

export type StateOrganizations = { lga: NamedRef; sites: SiteRow[] }[];

export type StateIncidents = {
    byType: { type: string; count: number }[];
    byLga: { lga: NamedRef; count: number }[];
    total: number;
};
