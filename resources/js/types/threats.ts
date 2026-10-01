import type { ClassificationLevel } from '@/types/auth';
import type { IncidentListRow } from '@/types/incidents';
import type { FlashpointRow } from '@/types/flashpoints';
import type { NamedRef, Option } from '@/types/local-government';

export type ThreatUrgency = 'routine' | 'priority' | 'immediate';

export type ThreatStatus =
    'active' | 'mitigated' | 'materialised' | 'expired' | 'withdrawn';

export type ThreatRow = {
    ulid: string;
    reference_no: string;
    title: string;
    threat_type: NamedRef;
    credibility: Option;
    urgency: { value: ThreatUrgency; label: string };
    status: { value: ThreatStatus; label: string };
    classification: { value: ClassificationLevel; label: string };
    lga: NamedRef;
    state: NamedRef;
    latitude: number | null;
    longitude: number | null;
    radius_meters: number | null;
    expected_from: string | null;
    expected_until: string | null;
    expires_at: string;
    expiring_soon: boolean;
    /** Seen only because another jurisdiction shared it (Phase 7A). */
    shared?: boolean;
    created_at: string;
};

export type ThreatDetail = ThreatRow & {
    description: string;
    source_type: Option;
    source_notes: string | null;
    target_description: string | null;
    community: NamedRef | null;
    recommended_action: string | null;
    status_note: string | null;
    created_by: string | null;
    /** For datetime-local inputs (Africa/Lagos). */
    expected_from_local: string | null;
    expected_until_local: string | null;
    expires_at_local: string;
};

export type ExplainedOption = Option & { help: string };

export type ThreatOptions = {
    credibilities: ExplainedOption[];
    urgencies: ExplainedOption[];
    sourceTypes: Option[];
    statuses: Option[];
    settableStatuses: Option[];
    classifications: Option[];
    groupRoles: Option[];
    incidentRelations: Option[];
    threatTypes: Option[];
    defaultExpiryDays: number;
    maxExpiryDays: number;
};

export type ThreatLinks = {
    flashpoints: (FlashpointRow & { link: string })[];
    groups: {
        ulid: string;
        name: string;
        type: Option;
        lga: NamedRef;
        state: NamedRef;
        role: Option;
        link: string;
    }[];
    incidents: (IncidentListRow & { relation: Option; link: string })[];
};

export type TimelineEntry = {
    event: string;
    description: string;
    at: string;
    by: string | null;
    comment: string | null;
};

/** The threat report sections of an incident page. */
export type IncidentThreats = {
    linked: (ThreatRow & { relation: Option })[];
    possible: (ThreatRow & { can_materialise: boolean })[];
};

export type LgaThreatFigures = { active: ThreatRow[]; channel: string };

export type StateThreatFigures = {
    byUrgency: (Option & { total: number })[];
    expiringSoon: ThreatRow[];
    recentlyMaterialised: ThreatRow[];
    channel: string;
};

export type NationalThreatFigures = {
    immediate: ThreatRow[];
    byStateAndType: { state: string; type: string; total: number }[];
    channel: string;
};
