import type { Option } from '@/types/local-government';

/** Analytics (Phase 7C). */
export type AnalyticsColumn = { key: string; label: string; numeric?: boolean };

export type AnalyticsChartData = {
    key: string;
    title: string;
    kind: 'line' | 'bar' | 'stacked' | 'table' | 'heatmap' | 'stats';
    labels: string[];
    series: { label: string; data: (number | null)[]; comparison?: boolean }[];
    columns: AnalyticsColumn[];
    rows: Record<string, string | number | null>[];
    note: string | null;
    link: { path: string; query: Record<string, string> } | null;
};

export type AnalyticsFilterValues = {
    period?: string;
    from?: string;
    to?: string;
    compare?: string;
    granularity?: string;
    zone?: string;
    state?: string;
    lga?: string;
    types?: string[];
    category?: string;
    severities?: string[];
};

export type AnalyticsFiltersInfo = {
    values: AnalyticsFilterValues;
    start: string;
    end: string;
    label: string;
    comparison: { start: string; end: string; label: string } | null;
    granularity: string;
    zone: { ulid: string; name: string } | null;
    state: { ulid: string; name: string } | null;
    lga: { ulid: string; name: string } | null;
};

export type AnalyticsOptions = {
    presets: Option[];
    comparisons: Option[];
    granularities: Option[];
    zones: Option[];
    states: Option[];
    lgas: Option[];
    types: Option[];
    categories: Option[];
    severities: Option[];
};
