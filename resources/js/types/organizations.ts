// Organizations (Phase 4B). Identified by ULID only. Contact phone and
// email appear only in ContactDetail (each view is logged on the server).

import type { ClassificationLevel } from '@/types/auth';
import type { FormationRow } from '@/types/formations';
import type { NamedRef, Option } from '@/types/local-government';

/** Authorised logo URLs (Phase 11D); a new logo has new URLs. */
export type LogoUrls = { thumb: string; full: string };

export type OrganizationRow = {
    ulid: string;
    name: string;
    other_names: string[];
    type: Option;
    sector: NamedRef;
    status: Option;
    rc_number: string | null;
    logo: LogoUrls | null;
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
    organization: NamedRef & { logo: LogoUrls | null };
    /** The cover photo's thumbnail URL (Phase 11D). */
    cover: string | null;
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

/** A site photo (Phase 11D); every URL is an authorised route. */
export type SitePhoto = {
    ulid: string;
    caption: string | null;
    taken_on: string | null;
    is_cover: boolean;
    uploaded_by: string | null;
    uploaded_at: string | null;
    urls: { thumb: string; preview: string; original: string };
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
