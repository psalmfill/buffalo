/** A row of a bulk user import, as classified by the server (Phase 9B.2). */
export type UserImportRow = {
    line: number;
    status: 'new' | 'exists' | 'error';
    errors: string[];
    name: string;
    email: string;
    phone: string;
    service_number: string;
    rank: string;
    role: string;
    role_label: string;
    state_code: string;
    lga_code: string;
    zone_codes: string;
    place: string;
};

export type OnboardingStage = 'no_link' | 'link_issued' | 'no_2fa' | 'ready';

/** A user on the onboarding or handover page. */
export type OnboardingUser = {
    ulid: string;
    name: string;
    email: string;
    role: string;
    place: string;
    stage: OnboardingStage;
};

export const STAGE_LABELS: Record<OnboardingStage, string> = {
    no_link: 'Setup pending, no link',
    link_issued: 'Link issued, not used yet',
    no_2fa: '2FA not enrolled',
    ready: 'Ready',
};
