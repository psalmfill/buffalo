/** Onboarding progress for one state, or national users (Phase 9B.2/9B.3). */
export type OnboardingRow = {
    state: {
        ulid: string;
        code: string;
        name: string;
        is_live: boolean;
        went_live_at: string | null;
    } | null;
    total: number;
    /** Setup pending, no usable setup link. */
    no_link: number;
    /** Setup pending, a setup link is waiting to be used. */
    link_issued: number;
    /** Password chosen, two-factor not enrolled yet. */
    no_2fa: number;
    /** Two-factor enrolled. */
    ready: number;
};
