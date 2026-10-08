// Political information, persons of interest and criminal groups
// (Phase 3B). ULIDs and labels only; numeric ids never reach the browser.

import type { ClassificationLevel } from '@/types/auth';
import type { NamedRef, Option } from '@/types/local-government';
import type { PersonRow } from '@/types/persons';

export type OfficeTypeOption = Option & {
    requiresDistrict: boolean;
    requiresLga: boolean;
    requiresWard: boolean;
};

export type PoliticalOffice = {
    ulid: string;
    office: Option;
    title: string;
    office_title: string | null;
    party: string | null;
    state: NamedRef;
    senatorial_district: NamedRef | null;
    lga: NamedRef | null;
    ward: NamedRef | null;
    constituency_name: string | null;
    started_on: string;
    ended_on: string | null;
    current: boolean;
    person: NamedRef | null;
    can?: { update: boolean; delete: boolean; reassign?: boolean };
};

export type TimelineEntry = {
    event: string;
    description: string;
    old: unknown;
    new: unknown;
    comment: string | null;
    by: string | null;
    at: string | null;
};

export type PoiRow = {
    ulid: string;
    person: PersonRow;
    risk_level: Option;
    status: Option;
    offence_categories: Option[];
    lga: NamedRef;
    last_sighted_at: string | null;
    review_due_at: string;
    review_overdue: boolean;
};

export type PoiDetail = PoiRow & {
    offence_codes: string[];
    known_offences: string;
    modus_operandi: string | null;
    latitude: number | null;
    longitude: number | null;
    last_known_location_description: string | null;
    last_reviewed_at: string | null;
    last_reviewed_by: string | null;
    created_at: string | null;
    timeline: TimelineEntry[];
    can: { update: boolean; clear: boolean; delete: boolean };
};

export type PoiOptions = {
    riskLevels: Option[];
    statuses: Option[];
    offenceCategories: Option[];
};

export type GroupRow = {
    ulid: string;
    name: string;
    other_names: string[];
    type: Option;
    status: Option;
    estimated_size: number | null;
    classification: { value: ClassificationLevel; label: string };
    lga: NamedRef;
    state: NamedRef;
    member_count: number;
};

export type GroupDetail = GroupRow & {
    description: string;
    created_at: string | null;
    updated_at: string | null;
};

export type GroupArea = {
    ulid: string;
    lga: NamedRef;
    state: NamedRef;
    notes: string | null;
    latitude: number | null;
    longitude: number | null;
};

export type GroupMember = {
    ulid: string;
    person: PersonRow | null;
    group: GroupRow | null;
    role_in_group: string | null;
    membership_status: Option;
    since: string | null;
    notes: string | null;
    is_poi: boolean;
};

export type GroupOptions = {
    types: Option[];
    statuses: Option[];
    membershipStatuses: Option[];
    classifications: Option[];
};

export type LgaLeadership = {
    chairman: PoliticalOffice[];
    viceChairman: PoliticalOffice[];
    councillors: PoliticalOffice[];
    traditionalRulers: { community: NamedRef; person: NamedRef }[];
    religiousLeaders: {
        institution: NamedRef & { religion: string };
        person: NamedRef;
    }[];
    communityLeaders: PersonRow[];
};
