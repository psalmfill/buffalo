// Numeric ids are never sent to the browser (CLAUDE.md): users are
// identified by ULID.
export type User = {
    ulid: string;
    name: string;
    email: string;
    avatar?: string;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
