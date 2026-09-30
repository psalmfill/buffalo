// Organizations (Phase 4B). Identified by ULID only. Contact phone and
// email appear only in ContactDetail (each view is logged on the server).

import type { ClassificationLevel } from '@/types/auth';
import type { FormationRow } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

export type OrganizationRow = {
    ulid: string;
    name: string;
    other_names: string[];
    type: Option;
    sector: NamedRef;
    status: Option;
    rc_number: string | null;
    /** Sites the viewer can see (never a count of other sites). */
    visible_sites: number;
};

export type OrganizationDetail = OrganizationRow & {
    website: string | null;
    description: string | null;
    is_private_security: boolean;
    created_at: string | null;
    updated_at: string | null;
};

export type Relevance = 'low' | 'medium' | 'high' | 'critical_infrastructure';

export type SiteRow = {
    ulid: string;
    name: string;
    organization: NamedRef;
    site_type: Option;
    security_relevance: { value: Relevance; label: string };
    status: Option;
    classification: { value: ClassificationLevel; label: string };
    lga: NamedRef;
    state: NamedRef;
    has_private_security: boolean;
    employees_estimate: number | null;
    latitude: number | null;
    longitude: number | null;
};

export type SiteDetail = SiteRow & {
    community: NamedRef | null;
    address: string | null;
    security_notes: string | null;
    security_provider: (NamedRef & { is_private_security: boolean }) | null;
    created_at: string | null;
    updated_at: string | null;
};

export type ContactDetail = {
    ulid: string;
    name: string;
    position: string;
    phone: string | null;
    email: string | null;
    is_management: boolean;
    is_security_contact: boolean;
    is_primary: boolean;
    notes: string | null;
    person: NamedRef | null;
    /** A person is linked whom the viewer cannot see. */
    person_hidden: boolean;
};

export type FormationLink = {
    ulid: string;
    formation: FormationRow;
    arrangement: Option;
    notes: string | null;
};

export type OrganizationOptions = {
    types: Option[];
    statuses: Option[];
    siteTypes: Option[];
    siteStatuses: Option[];
    relevance: Option[];
    arrangements: Option[];
    classifications: Option[];
};
