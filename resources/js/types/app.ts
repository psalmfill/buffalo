export type BannerLevel = 'info' | 'restricted' | 'confidential' | 'secret';

export type SystemBanner = {
    text: string;
    level: BannerLevel;
};

/** Shared as `app` on every page, including error pages. */
export type AppData = {
    name: string;
    /** Null when BUFFALO_BANNER_TEXT is empty. */
    banner: SystemBanner | null;
    sessionIdleMinutes: number;
    /** The UAT environment (Phase 9B.5): shows the feedback button. */
    uat: boolean;
};
