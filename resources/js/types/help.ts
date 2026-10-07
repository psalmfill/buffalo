/** A manual in the Help index (Phase 9B.4). */
export type ManualEntry = {
    kind: 'start' | 'roles' | 'guides' | 'cards';
    slug: string;
    title: string;
    summary: string;
    /** The user's own role manual or card. */
    own: boolean;
    role?: string | null;
};

export type TocEntry = { id: string; text: string; level: number };
