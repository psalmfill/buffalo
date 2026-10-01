/** Dissemination of threat reports and flashpoints (Phase 7A). */

export type DisseminationStatus =
    'unacknowledged' | 'acknowledged' | 'expired' | 'revoked';

export type DisseminationRow = {
    ulid: string;
    level: 'state' | 'lga';
    target: string;
    status: DisseminationStatus;
    onward: boolean;
    reason: string;
    disseminated_at: string;
    disseminated_by: string | null;
    expires_at: string;
    acknowledged_at: string | null;
    acknowledged_by: string | null;
    revoked_at: string | null;
    revocation_reason: string | null;
    can: { renew: boolean; revoke: boolean };
};

export type TargetOption = {
    level: 'state' | 'lga';
    ulid: string;
    name: string;
    state: string;
    covered?: boolean;
};

/** Which officers of a target can see the record at its classification. */
export type Audience = {
    state: string[];
    lga: string[];
    hidden: { state: string[]; lga: string[] };
};

export type Place = {
    ulid: string;
    name: string;
    selectable: boolean;
    lgas: { ulid: string; name: string }[];
};

export type DisseminationRequestRow = {
    ulid: string;
    status: { value: 'pending' | 'actioned' | 'declined'; label: string };
    reason: string;
    targets: TargetOption[];
    requested_by: string | null;
    requested_at: string;
    decided_by: string | null;
    decided_at: string | null;
    decision_note: string | null;
    can_decide: boolean;
};

export type Sharing = {
    disseminations: DisseminationRow[];
    suggestions: (TargetOption & { covered: boolean })[];
    preselect: boolean;
    audience: Audience;
    places: Place[] | null;
    requests: DisseminationRequestRow[];
    can: { disseminate: boolean; request: boolean };
};

export type SharedBanner = {
    ulid: string;
    active: string[];
    source: string;
    source_lga: string;
    target: string;
    disseminated_at: string;
    expires_at: string;
    reason: string;
    acknowledged: boolean;
    acknowledge: string | null;
    onward: {
        ulid: string;
        lgas: { ulid: string; name: string }[];
        audience: Audience;
    } | null;
};

export type DisseminationPrompt = {
    mode: 'disseminate' | 'request';
    places: string[];
};

export type DisseminationProps = {
    dissemination: SharedBanner | null;
    sharing: Sharing | null;
    disseminationPrompt: DisseminationPrompt | null;
};

export type SubjectRef = {
    type: 'threat_report' | 'flashpoint';
    kind: string;
    label: string;
    title?: string | null;
    threat_type?: string | null;
    href: string;
};

export type InboxRow = DisseminationRow & {
    subject: SubjectRef;
    source: string;
    source_lga: string;
    can_acknowledge: boolean;
};

export type InboxRequestRow = DisseminationRequestRow & { subject: SubjectRef };

export type SharedItem = {
    ulid: string;
    kind: string;
    label: string;
    source: string;
    disseminated_at: string;
    href: string;
};

export type CrossBorderItem = {
    kind: string;
    label: string;
    state: string | null;
    places: string[];
    href: string;
};
