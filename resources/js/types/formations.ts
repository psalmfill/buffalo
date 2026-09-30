// Agency formations (Phase 4A). Identified by ULID only.

import type { ClassificationLevel } from '@/types/auth';
import type { NamedRef, Option } from '@/types/local-government';

export type AgencyRef = { ulid: string; name: string; short_name: string };

export type FormationRow = {
    ulid: string;
    name: string;
    agency: AgencyRef;
    formation_type: Option;
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    lga: NamedRef;
    state: NamedRef;
    is_temporary: boolean;
    active_from: string | null;
    active_to: string | null;
    personnel_strength: number | null;
    latitude: number | null;
    longitude: number | null;
};

export type FormationDetail = FormationRow & {
    parent: NamedRef | null;
    /** A parent is recorded that the viewer cannot see. */
    parent_hidden: boolean;
    community: NamedRef | null;
    address: string | null;
    official_phone: string | null;
    commander_title: string | null;
    commander_name: string | null;
    commander_rank: string | null;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
};

export type FormationOptions = {
    types: Option[];
    statuses: Option[];
    classifications: Option[];
};

export type AgencyOption = Option & { state: string | null };
