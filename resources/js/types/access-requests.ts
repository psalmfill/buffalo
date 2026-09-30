// Cross-jurisdiction access (Phase 3C). A hit carries only the owning
// state, the category, the strength and an opaque token: never a name,
// ULID, LGA, photo or offence.

import type { Option } from '@/types/local-government';

export type Hit = {
    token: string;
    state: string;
    category: string;
    strength: string;
};

export type CrossJurisdiction = {
    hits: Hit[];
    limitReached: boolean;
};

export type GrantSummary = {
    ulid: string;
    scope: Option;
    starts_at: string;
    expires_at: string;
    revoked_at: string | null;
    revocation_reason: string | null;
    active: boolean;
};

export type AccessRequestRow = {
    ulid: string;
    status: Option;
    urgency: Option;
    scope: Option;
    requested_days: number;
    granted_scope: Option | null;
    granted_days: number | null;
    owning_state: string;
    requester: { ulid: string; name: string; place: string };
    match_type: Option | null;
    created_at: string;
    escalated_at: string | null;
    decided_at: string | null;
    grant: GrantSummary | null;
    /** Only when the viewer can see the record (owner, oversight, active grant). */
    record: { ulid: string; name: string; classification: Option } | null;
};

export type GrantRow = GrantSummary & {
    request: AccessRequestRow;
    granted_by: string | null;
    can_revoke: boolean;
};

export type MatchEventRow = {
    officer: { ulid: string; name: string; place: string };
    owning_state: string;
    match_type: Option;
    context: Option;
    suppressed: boolean;
    created_at: string;
    record: { ulid: string; name: string } | null;
};

export type DelegationRow = {
    ulid: string;
    delegator: string;
    delegate: string;
    starts_at: string;
    ends_at: string | null;
    reason: string | null;
    revoked_at: string | null;
    end_reason: string | null;
    active: boolean;
    can_revoke: boolean;
};

export type AccessRequestOptions = {
    scopes: (Option & { description: string })[];
    urgencies: Option[];
    days: Option[];
};

/** The grant under which a person page is shown. */
export type GrantBanner = {
    ulid: string;
    scope: Option;
    grantedBy: string;
    state: string;
    expiresAt: string;
};
