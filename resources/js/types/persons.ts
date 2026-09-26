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
    is_active: boolean;
    notes: string | null;
};

export type PersonDetail = PersonRow & {
    first_name: string;
    last_name: string;
    other_names: string | null;
    state: NamedRef;
    lga: NamedRef;
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
    lga: string;
    gender: string;
    classification: string;
};

/** A person linked from another record (traditional ruler, leader). */
export type LinkedPerson = {
    person: NamedRef | null;
    /** A person is recorded whom the viewer cannot see. */
    hidden: boolean;
};
