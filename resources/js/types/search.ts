import type { ClassificationLevel } from '@/types/auth';

/** Global search (Phase 7B). */
export type SearchHit = {
    type: string;
    ulid: string;
    title: string;
    subtitle: string;
    href: string;
    classification: { value: ClassificationLevel; label: string } | null;
    score: number;
    exact: boolean;
    badge: string | null;
    /** An authorised thumbnail URL (Phase 11D: logos, site cover photos). */
    image: string | null;
};

export type QuickSection = {
    key: string;
    label: string;
    hits: SearchHit[];
    more: boolean;
    timedOut: boolean;
    tookMs: number;
};

export type QuickResults = {
    query: string;
    type: 'reference' | 'nin' | 'phone' | 'text' | null;
    sections: QuickSection[];
    top: SearchHit[];
};

export type SearchTab = {
    key: string;
    label: string;
    count: number | null;
    timedOut: boolean;
    tookMs: number;
};

export type FullResults = {
    query: string;
    type: QuickResults['type'];
    tabs: SearchTab[];
    active: string | null;
    hits: SearchHit[];
    page: number;
    pages: number;
    /** Most results listed per type; counts stay exact. */
    limit: number;
    timedOut: boolean;
};
