// Numeric ids are never sent to the browser (CLAUDE.md): records are
// identified by ULID.

/**
 * Every permission name (mirror of App\Enums\Permission; a PHP test keeps
 * the two in step).
 */
export const PERMISSIONS = [
    'users.view',
    'users.manage',
    'audit.view',
    'login_history.view',
    'system.horizon',
    'system.settings',
    'reference_data.view',
    'reference_data.manage',
] as const;

export type Permission = (typeof PERMISSIONS)[number];

export type RoleName =
    | 'super_admin'
    | 'director_general'
    | 'deputy_director_general'
    | 'state_director'
    | 'deputy_state_director'
    | 'lga_security_chief';

export type JurisdictionLevel = 'system' | 'national' | 'state' | 'lga';

export type NamedRef = {
    ulid: string;
    name: string;
};

export type Jurisdiction = {
    level: JurisdictionLevel;
    state: NamedRef | null;
    lga: NamedRef | null;
    /** Zone names for a zoned DDG; empty otherwise. */
    zones: string[];
};

/** The signed-in user (shared as auth.user on every page). */
export type User = {
    ulid: string;
    name: string;
    email: string;
    role: { value: RoleName; label: string } | null;
    jurisdiction: Jurisdiction;
    /** e.g. "National", "North West Zone", "Lagos State", "Ikeja LGA, Lagos State". */
    jurisdictionLabel: string;
};

export type Auth = {
    /** Null on guest pages (sign-in, account setup, error pages). */
    user: User | null;
    permissions: Permission[];
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
