import type { ClassificationLevel } from '@/types/auth';
import type { Option } from '@/types/local-government';

/** Arrests (Phase 11C). Numeric ids never reach the browser. */
export type ArrestRow = {
    ulid: string;
    reference_no: string;
    arrested_at: string;
    arrested_at_precision: Option;
    agency: { ulid: string; name: string; short_name: string };
    persons_count: number;
    offences: string[];
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    lga: { ulid: string; name: string };
    incident: {
        ulid: string;
        reference_no: string | null;
        title: string;
    } | null;
};

export type ArrestDetail = {
    ulid: string;
    reference_no: string;
    arrested_at: string;
    arrested_at_local: string;
    arrested_at_precision: Option;
    agency: { ulid: string; name: string; short_name: string };
    formation: { ulid: string; name: string } | null;
    formation_hidden: boolean;
    lga: { ulid: string; name: string };
    state: { ulid: string; name: string };
    community: { ulid: string; name: string } | null;
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    incident: {
        ulid: string;
        reference_no: string | null;
        title: string;
        status: Option;
    } | null;
    incident_hidden: boolean;
    status: Option;
    notes: string | null;
    classification: { value: ClassificationLevel; label: string };
    created_at: string;
};

export type ArrestSuggestion =
    | { kind: 'poi_detained'; poi: string; person: string; current: string }
    | { kind: 'designate_poi'; person: string };

export type ArrestPersonRow = {
    ulid: string;
    person: { ulid: string; name: string } | null;
    person_hidden: boolean;
    unidentified_description: string | null;
    status: Option;
    notes: string | null;
    offences: { offence: Option; details: string | null }[];
    suggestion: ArrestSuggestion | null;
};

export type ArrestComparison = {
    reported: number;
    recorded: number;
    differs: boolean;
};

export type ArrestOptions = {
    statuses: Option[];
    precisions: Option[];
    classifications: Option[];
    agencies: Option[];
    offences: Option[];
};

export type ArrestTimelineEntry = {
    at: string;
    who: string | null;
    scope: string;
    from: Option | null;
    to: Option;
    comment: string;
};

/** What an incident pre-fills on a new arrest (Phase 11C.3). */
export type ArrestPrefill = {
    incident: { value: string; label: string };
    lga: string;
    community: string | null;
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    agencies: { value: string; label: string; formation: string | null }[];
    suspects: {
        person: { value: string; label: string } | null;
        unidentified_description: string | null;
    }[];
    occurred_at: string;
    incident_status: string | null;
};
