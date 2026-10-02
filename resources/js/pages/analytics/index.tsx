import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import AnalyticsController from '@/actions/App/Http/Controllers/Analytics/AnalyticsController';
import ChartCard from '@/components/analytics/chart-card';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import NativeSelect from '@/components/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type {
    AnalyticsChartData,
    AnalyticsFilterValues,
    AnalyticsFiltersInfo,
    AnalyticsOptions,
} from '@/types/analytics';

type Props = {
    tabs: { key: string; label: string }[];
    tab: string | null;
    charts: AnalyticsChartData[];
    filters: AnalyticsFiltersInfo;
    incidentQuery: Record<string, string>;
    options: AnalyticsOptions;
    marking: { classification: string; user: string };
    can: { export: boolean };
};

/**
 * Analytics (Phase 7C): one tab per area, every figure through the viewer's
 * own scopes (drafts and invalid reports never counted). All filters are in
 * the URL, so a view can be shared; each viewer still sees only their own
 * records.
 */
export default function AnalyticsIndex({
    tabs,
    tab,
    charts,
    filters,
    incidentQuery,
    options,
    marking,
    can,
}: Props) {
    const [values, setValues] = useState<AnalyticsFilterValues>({
        period: '30d',
        compare: 'none',
        ...filters.values,
        from: filters.values.from ?? filters.start,
        to: filters.values.to ?? filters.end,
    });

    const apply = (
        next: AnalyticsFilterValues,
        nextTab: string | null = tab,
    ) => {
        const query: Record<string, string | string[]> = {};
        Object.entries(next).forEach(([k, v]) => {
            if (
                v === undefined ||
                v === '' ||
                (Array.isArray(v) && v.length === 0)
            ) {
                return;
            }
            if ((k === 'from' || k === 'to') && next.period !== 'custom') {
                return;
            }
            if (k === 'compare' && v === 'none') {
                return;
            }
            query[k] = v;
        });

        router.get(
            AnalyticsController.index.url(),
            { ...(nextTab ? { tab: nextTab } : {}), ...query },
            { preserveScroll: true },
        );
    };

    const set = (key: keyof AnalyticsFilterValues, value: string) => {
        const next = {
            ...values,
            [key]:
                key === 'types' || key === 'severities'
                    ? value
                        ? [value]
                        : []
                    : value,
        };

        if (key === 'state') {
            next.lga = '';
        }
        if (key === 'zone') {
            next.state = '';
            next.lga = '';
        }

        setValues(next);

        if (key !== 'from' && key !== 'to') {
            apply(next);
        }
    };

    const select = (
        key: keyof AnalyticsFilterValues,
        label: string,
        list: { value: string; label: string }[],
        empty: string | null,
    ) => {
        const current = values[key];
        const value = Array.isArray(current)
            ? (current[0] ?? '')
            : (current ?? '');

        return (
            <div className="grid gap-1">
                <Label htmlFor={`f-${key}`} className="text-xs">
                    {label}
                </Label>
                <NativeSelect
                    id={`f-${key}`}
                    value={value}
                    onChange={(e) => set(key, e.target.value)}
                    className="h-8 w-auto min-w-36"
                >
                    {empty !== null && <option value="">{empty}</option>}
                    {list.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
        );
    };

    return (
        <>
            <Head title="Analytics" />
            <div className="space-y-5 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Analytics"
                        description={`${filters.label}${filters.comparison ? ` compared with ${filters.comparison.label}` : ''} · by ${filters.granularity}${filters.lga ? ` · ${filters.lga.name} LGA` : filters.state ? ` · ${filters.state.name}` : filters.zone ? ` · ${filters.zone.name} Zone` : ''}`}
                    />
                    <p className="max-w-sm text-right text-xs text-muted-foreground">
                        Figures include only records you can see; drafts and
                        invalid reports are never counted. Exports are marked{' '}
                        {marking.classification}.
                    </p>
                </div>

                <div className="flex flex-wrap items-end gap-3 rounded-lg border bg-card p-3">
                    {select('period', 'Period', options.presets, null)}
                    {values.period === 'custom' && (
                        <>
                            <div className="grid gap-1">
                                <Label htmlFor="f-from" className="text-xs">
                                    From
                                </Label>
                                <Input
                                    id="f-from"
                                    type="date"
                                    value={values.from ?? ''}
                                    onChange={(e) =>
                                        set('from', e.target.value)
                                    }
                                    className="h-8"
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="f-to" className="text-xs">
                                    To
                                </Label>
                                <Input
                                    id="f-to"
                                    type="date"
                                    value={values.to ?? ''}
                                    onChange={(e) => set('to', e.target.value)}
                                    className="h-8"
                                />
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => apply(values)}
                            >
                                Apply dates
                            </Button>
                        </>
                    )}
                    {select(
                        'compare',
                        'Compare with',
                        options.comparisons,
                        null,
                    )}
                    {select(
                        'granularity',
                        'Granularity',
                        options.granularities,
                        `Automatic (${filters.granularity})`,
                    )}
                    {options.zones.length > 0 &&
                        select('zone', 'Zone', options.zones, 'All zones')}
                    {options.states.length > 0 &&
                        select('state', 'State', options.states, 'All states')}
                    {options.lgas.length > 1 &&
                        select('lga', 'LGA', options.lgas, 'All LGAs')}
                    {select(
                        'category',
                        'Category',
                        options.categories,
                        'All categories',
                    )}
                    {select('types', 'Type', options.types, 'All types')}
                    {select(
                        'severities',
                        'Severity',
                        options.severities,
                        'All severities',
                    )}
                    <Link
                        href={`/incidents?${new URLSearchParams(incidentQuery).toString()}`}
                        className="ml-auto self-center text-xs underline"
                    >
                        See these incidents
                    </Link>
                </div>

                <nav
                    className="flex flex-wrap gap-1 border-b"
                    aria-label="Analytics areas"
                >
                    {tabs.map((t) => (
                        <button
                            key={t.key}
                            type="button"
                            onClick={() => apply(values, t.key)}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === t.key
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === t.key ? 'page' : undefined}
                        >
                            {t.label}
                        </button>
                    ))}
                </nav>

                {tab === null ? (
                    <EmptyState
                        title="No analytics available"
                        description="Your role has no analytics areas."
                    />
                ) : (
                    <div className="grid gap-4 xl:grid-cols-2">
                        {charts.map((chart) => (
                            <div
                                key={chart.key}
                                className={cn(
                                    (chart.kind === 'table' ||
                                        chart.kind === 'heatmap') &&
                                        'xl:col-span-2',
                                )}
                            >
                                <ChartCard
                                    area={tab}
                                    chart={chart}
                                    filters={filters.values}
                                    canExport={can.export}
                                />
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

AnalyticsIndex.layout = {
    breadcrumbs: [{ title: 'Analytics', href: AnalyticsController.index() }],
};
