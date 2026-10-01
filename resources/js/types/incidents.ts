// Incidents (Phase 5A). Identified by ULID only. Linked persons are named
// only to viewers who can see them.

import type { ClassificationLevel } from '@/types/auth';
import type { DetailValues, FieldDefinition } from '@/types/dynamic-fields';
import type { EvidenceProps } from '@/types/evidence';
import type { AgencyRef } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

export type IncidentTypeOption = {
    ulid: string;
    name: string;
    category: Option;
    default_severity: string;
    fields: FieldDefinition[];
    schema_version: number;
};

export type IncidentRow = {
    ulid: string;
    reference_no: string | null;
    title: string;
    type: NamedRef;
    severity: Option;
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    occurred_at: string;
    occurred_at_precision: Option;
    lga: NamedRef;
    state: NamedRef;
    updated_at: string | null;
};

export type IncidentDetail = Omit<IncidentRow, 'type'> & {
    type: IncidentTypeOption;
    details: DetailValues;
    details_schema_version: number;
    description: string;
    /** For a datetime-local input (Africa/Lagos). */
    occurred_at_local: string;
    community: NamedRef | null;
    latitude: number | null;
    longitude: number | null;
    location_description: string | null;
    action_taken: string | null;
    created_at: string | null;
    workflow: IncidentWorkflow;
};

export type WorkflowStep = { by: string | null; at: string } | null;

export type IncidentWorkflow = {
    submitted: WorkflowStep;
    returned: WorkflowStep;
    return_reason: string | null;
    verified: WorkflowStep;
    investigation_started: WorkflowStep;
    resolved: WorkflowStep;
    resolution_summary: string | null;
    closed: WorkflowStep;
    invalidated: WorkflowStep;
    invalid_reason: Option | null;
    invalid_note: string | null;
    duplicate_of: {
        ulid: string;
        reference_no: string | null;
        title: string;
    } | null;
    escalation: {
        at: string;
        by: string | null;
        automatic: boolean;
        reason: string | null;
        acknowledged: WorkflowStep;
    } | null;
    locked: boolean;
    requires_amendment_reason: boolean;
};

export type DuplicateRow = {
    ulid: string;
    reference_no: string | null;
    title: string;
    type: string;
    occurred_at: string;
    status: Option;
};

export type TimelineEntry = {
    at: string;
    kind: string;
    label: string;
    body: string | null;
    who: string | null;
    classification: { value: ClassificationLevel; label: string } | null;
};

export type WorkflowCan = {
    update: boolean;
    submit: boolean;
    return: boolean;
    verify: boolean;
    startInvestigation: boolean;
    resolve: boolean;
    reopen: boolean;
    close: boolean;
    invalidate: boolean;
    escalate: boolean;
    acknowledge: boolean;
    addUpdate: boolean;
};

export type WorkflowOptions = {
    invalidReasons: Option[];
    updateTypes: Option[];
    updateLevels: Option[];
};

export type CasualtyRow = {
    ulid: string;
    outcome: Option;
    group: Option;
    agency: AgencyRef | null;
    adult_male: number;
    adult_female: number;
    child_male: number;
    child_female: number;
    unknown: number;
    total: number;
};

export type IncidentPersonRow = {
    ulid: string;
    person: NamedRef | null;
    /** A person on record whom the viewer cannot see. */
    person_hidden: boolean;
    unidentified_description: string | null;
    involvement: Option;
    notes: string | null;
    can_designate_poi: boolean;
};

export type IncidentGroupRow = {
    ulid: string;
    group: NamedRef | null;
    group_hidden: boolean;
    role: Option;
    notes: string | null;
};

export type IncidentAgencyRow = {
    ulid: string;
    agency: AgencyRef;
    formation: NamedRef | null;
    formation_hidden: boolean;
    role: Option;
    responded_at: string | null;
    responded_at_local: string | null;
    notes: string | null;
};

export type IncidentPropertyRow = {
    ulid: string;
    property_type: NamedRef;
    description: string | null;
    quantity: number | null;
    estimated_value_naira: string | null;
    site: NamedRef | null;
    site_hidden: boolean;
};

export type IncidentOptions = {
    types: IncidentTypeOption[];
    severities: Option[];
    precisions: Option[];
    outcomes: Option[];
    groups: Option[];
    involvements: Option[];
    groupRoles: Option[];
    agencyRoles: Option[];
    classifications: Option[];
    agencies: Option[];
    propertyTypes: Option[];
};

export type IncidentRecord = {
    incident: IncidentDetail;
    casualties: CasualtyRow[];
    persons: IncidentPersonRow[];
    groups: IncidentGroupRow[];
    agencies: IncidentAgencyRow[];
    properties: IncidentPropertyRow[];
    evidence: EvidenceProps;
};

/** A row of the incident list, record tabs and dashboards (Phase 5D). */
export type IncidentListRow = {
    ulid: string;
    reference_no: string | null;
    title: string;
    type: { ulid: string; name: string; category: string };
    lga: NamedRef;
    state: NamedRef;
    occurred_at: string;
    severity: Option;
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    escalation: { acknowledged: boolean } | null;
    /** e.g. "3 killed, 2 abducted". */
    casualties: string;
    reporting_officer: string | null;
    /** Person tab only: their involvement. */
    involvement?: string | null;
    /** State dashboard only. */
    overdue?: boolean;
    submitted_at?: string | null;
};

/** Filter values as in the URL: ULIDs and enum values only. */
export type IncidentFilterValues = {
    q?: string;
    types?: string[];
    category?: string;
    severities?: string[];
    statuses?: string[];
    escalated?: 'yes' | 'no' | 'unacknowledged';
    from?: string;
    to?: string;
    state?: string;
    lga?: string;
    community?: string;
    has_casualties?: '1';
    outcome?: string;
    overdue?: '1';
    officer?: string;
    group?: string;
    mine?: '1';
    no_location?: '1';
    /** Phase 6C: an area from the operational map ("w,s,e,n" or a hexagon id). */
    bbox?: string;
    hex?: string;
    sort?: string;
    dir?: 'asc' | 'desc';
};

export type IncidentFilterOptions = {
    types: { value: string; label: string; category: string }[];
    categories: Option[];
    severities: Option[];
    statuses: Option[];
    outcomes: Option[];
    states: Option[];
    lgas: { value: string; label: string; state: string | null }[];
};

export type SavedIncidentFilter = {
    ulid?: string;
    name: string;
    filters: IncidentFilterValues;
};

export type IncidentListShared = {
    filters: IncidentFilterValues;
    filterLabels: IncidentFilterLabels;
    appliedStatuses: string[];
    defaultStatuses: string[];
    national: boolean;
    filterOptions: IncidentFilterOptions;
    savedFilters: SavedIncidentFilter[];
    defaultFilters: SavedIncidentFilter[];
    can: { create: boolean };
};

/** A map point: popup fields only. */
export type IncidentMapPoint = {
    ulid: string;
    reference_no: string | null;
    type: string;
    category: string;
    severity: string;
    status: string;
    occurred_at: string;
    lat: number;
    lng: number;
};

export type IncidentFilterLabels = {
    community: string | null;
    group: string | null;
    officer: string | null;
};
