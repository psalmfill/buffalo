// Incidents (Phase 5A). Identified by ULID only. Linked persons are named
// only to viewers who can see them.

import type { ClassificationLevel } from '@/types/auth';
import type { DetailValues, FieldDefinition } from '@/types/dynamic-fields';
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
};
