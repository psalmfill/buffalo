export type UserStatus =
    'active' | 'inactive' | 'locked' | 'setup_pending' | 'no_2fa';

export type RoleOption = {
    value: string;
    label: string;
    level: 'system' | 'national' | 'state' | 'lga';
    allowsZones: boolean;
};

export type NamedOption = { ulid: string; name: string };

export type LgaOption = NamedOption & { state: string | null };

export type JurisdictionFormOptions = {
    roles: RoleOption[];
    zones: NamedOption[];
    states: NamedOption[];
    lgas: LgaOption[];
};

export type UserRow = {
    ulid: string;
    name: string;
    email: string;
    service_number: string | null;
    rank: string | null;
    role: { value: string; label: string } | null;
    jurisdiction: string;
    statuses: UserStatus[];
    last_login_at: string | null;
};

export type UserDetail = UserRow & {
    phone: string | null;
    jurisdiction_level: 'system' | 'national' | 'state' | 'lga';
    state: NamedOption | null;
    lga: NamedOption | null;
    zones: NamedOption[];
    is_active: boolean;
    deactivated_at: string | null;
    deactivation_reason: string | null;
    locked_until: string | null;
    password_changed_at: string | null;
    two_factor_confirmed_at: string | null;
    last_login_ip: string | null;
    created_at: string | null;
};

export type Posting = {
    ulid: string;
    role: string;
    jurisdiction: string;
    position_title: string | null;
    started_at: string;
    ended_at: string | null;
};

export type LoginEntry = {
    event: string;
    email_attempted: string | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string | null;
    repeated_failures?: boolean;
    user?: { name: string; ulid: string } | null;
};

export type ActivityEntry = {
    log: string | null;
    event: string | null;
    description: string;
    causer: { label: string; ulid: string | null; type: string } | null;
    subject_type: string | null;
    subject: { label: string; ulid: string | null; type: string } | null;
    old: Record<string, unknown> | null;
    new: Record<string, unknown> | null;
    properties: Record<string, unknown>;
    ip_address: string | null;
    created_at: string | null;
};
