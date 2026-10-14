// Persons (Phase 3A). Identified by ULID only; numeric ids never reach the
// browser. Protected fields (NIN, phone, date of birth, address) are only
// in PersonDetail, which only the person page and its edit form receive.

import type { ClassificationLevel } from '@/types/auth';
import type { NamedRef, Option } from '@/types/local-government';

export type PersonRow = {
    ulid: string;
    name: string;
    gender: Option;
    roles: Option[];
    lga: NamedRef | null;
    state: NamedRef | null;
    classification: { value: ClassificationLevel; label: string };
    is_deceased: boolean;
    /** Authorised streaming URL of the current photo's thumbnail. */
    thumbnail: string | null;
};

export type PersonRoleEntry = {
    role: Option;
    /** Whether the role type is still active (Phase 11A.1). */
    type_active: boolean;
    is_active: boolean;
    notes: string | null;
};

export type PersonDetail = PersonRow & {
    first_name: string;
    last_name: string;
    other_names: string | null;
    state: NamedRef | null;
    lga: NamedRef | null;
    community: NamedRef | null;
    date_of_birth: string | null;
    approximate_age: number | null;
    nin: string | null;
    phone: string | null;
    address: string | null;
    occupation: string | null;
    nationality: string;
    description: string | null;
    all_roles: PersonRoleEntry[];
    created_at: string | null;
    updated_at: string | null;
};

export type PersonPhoto = {
    ulid: string;
    current: boolean;
    thumbnail: string;
    preview: string;
    original: string;
    added_at: string | null;
};

export type PersonAlias = {
    ulid: string;
    alias: string;
    added_at: string | null;
};

export type PersonAssociationRow = {
    ulid: string;
    person: PersonRow;
    /** The other person's relationship to this person. */
    relationship: Option;
    notes: string | null;
    added_at: string | null;
};

export type PersonOptions = {
    genders: Option[];
    /** Roles that may be set on the persons forms (not person of interest). */
    roles: Option[];
    allRoles: Option[];
    classifications: Option[];
    relationships: Option[];
};

export type SimilarPerson = PersonRow & { reasons: string[] };

export type PersonSearchFilters = {
    q: string;
    role: string;
    state: string;
    /** Phase 11A.2: a geopolitical zone (national users). */
    zone: string;
    lga: string;
    gender: string;
    classification: string;
    /** Optional date of birth, used only to check other jurisdictions by name. */
    dob: string;
};

/** A person linked from another record (traditional ruler, leader). */
export type LinkedPerson = {
    person: NamedRef | null;
    /** A person is recorded whom the viewer cannot see. */
    hidden: boolean;
};

/*
| Merging duplicate persons (Phase 10A)
*/

export type MergeSide = 'survivor' | 'source';

export type DuplicatePair = {
    a: PersonRow;
    b: PersonRow;
    strength: Option;
    signals: string[];
    /** ULID of a pending merge request for the pair, if any. */
    pendingRequest: string | null;
    can: { request: boolean; merge: boolean };
};

export type MergeReviewSide = PersonRow & {
    lga: NamedRef;
    created_at: string | null;
};

export type MergeField = {
    field: string;
    label: string;
    protected: boolean;
    survivor: string | null;
    source: string | null;
    conflict: boolean;
    automatic: MergeSide;
};

export type MergeVisibility = {
    /** Keep the survivor's LGA: the source's LGA loses sight. */
    survivor: { lga: string; incidents: number } | null;
    /** Take the source's LGA: the survivor's LGA loses sight. */
    source: { lga: string; incidents: number } | null;
};

export type MergeReview = {
    survivor: MergeReviewSide;
    source: MergeReviewSide;
    fields: MergeField[];
    blockers: string[];
    dobConflict: boolean;
    deceasedDiffers: boolean;
    classification: { value: ClassificationLevel; label: string };
    counts: Record<
        | 'aliases'
        | 'photos'
        | 'roles'
        | 'associations'
        | 'offices'
        | 'memberships'
        | 'incidents'
        | 'poi'
        | 'community_links',
        number
    >;
    endings: { grants: number; requests: number };
    visibility: MergeVisibility;
    photos: { survivor: string | null; source: string | null };
    poi: { survivor: string; source: string } | null;
    communities: {
        survivor: { name: string; lga: string | null } | null;
        source: { name: string; lga: string | null } | null;
    };
    request: { ulid: string; reason: string; requestedBy: string } | null;
};

export type MergeRequestRow = {
    ulid: string;
    a: PersonRow | null;
    b: PersonRow | null;
    suggestedSurvivor: string | null;
    reason: string;
    status: Option;
    requestedBy: string;
    lga: string | null;
    createdAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    can: { decide: boolean };
};

export type MergeEdit = {
    field: string;
    label: string;
    protected: boolean;
    current: string | null;
    before_merge: string | null;
};

export type MergedRecord = {
    ulid: string;
    /** Null when the merged record is outside the viewer's own view. */
    source: NamedRef | null;
    executedAt: string;
    executedBy: string;
    undoneAt: string | null;
    undoneBy: string | null;
    undoReason: string | null;
    undoDeadline: string | null;
    can: { undo: boolean };
    edits: MergeEdit[];
};
