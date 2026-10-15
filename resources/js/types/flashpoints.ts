import type { ClassificationLevel } from '@/types/auth';
import type { IncidentListRow } from '@/types/incidents';
import type { NoteRow } from '@/types/community-information';
import type { NamedRef, Option } from '@/types/local-government';

export type ConcernLevel = 'low' | 'moderate' | 'high' | 'severe';

export type FlashpointStatus = 'active' | 'monitoring' | 'dormant' | 'resolved';

export type FlashpointRow = {
    ulid: string;
    name: string;
    kind: { value: 'flashpoint' | 'blackspot'; label: string };
    threat_type: NamedRef;
    concern_level: { value: ConcernLevel; label: string };
    status: { value: FlashpointStatus; label: string };
    classification: { value: ClassificationLevel; label: string };
    /** Seen only because another jurisdiction shared it (Phase 7A). */
    shared?: boolean;
    lga: NamedRef;
    state: NamedRef;
    latitude: number;
    longitude: number;
    radius_meters: number;
    last_assessed_at: string;
    review_due_at: string;
    review_overdue: boolean;
};

export type FlashpointDetail = FlashpointRow & {
    description: string;
    recommended_action: string | null;
    community: NamedRef | null;
    last_assessed_by: string | null;
    created_by: string | null;
    created_at: string;
};

export type Assessment = {
    ulid: string;
    concern_level: { value: ConcernLevel; label: string };
    status: { value: FlashpointStatus; label: string };
    assessment: string;
    recommended_action: string | null;
    /** HTML since Phase 11B; older assessments are plain text. */
    format: 'text' | 'html';
    assessed_by: string | null;
    assessed_at: string;
};

export type FlashpointOptions = {
    kinds: Option[];
    concernLevels: Option[];
    statuses: Option[];
    classifications: Option[];
    threatTypes: Option[];
    /** Days to the next review, by concern level. */
    reviewDays: Record<ConcernLevel, number>;
};

export type LinkInfo = {
    ulid: string;
    note: string | null;
    linked_at: string;
    linked_by: string | null;
};

export type LinkedIncident = IncidentListRow & { link: LinkInfo };

export type LinkedNote = NoteRow & { link: LinkInfo };

/** The Flashpoints section of an incident page. */
export type IncidentFlashpoints = {
    linked: (FlashpointRow & { link_note: string | null })[];
    /** can_link: the viewer may link the incident from here. */
    near: (FlashpointRow & { can_link: boolean })[];
};
