import { Link } from '@inertiajs/react';
import { ArrowUpDown, Download, Image, Map as MapIcon } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import AnalyticsController from '@/actions/App/Http/Controllers/Analytics/AnalyticsController';
import { downloadChartPng } from '@/components/analytics/export-png';
import AnalyticsChart from '@/components/charts/analytics-chart';
import type { ChartHandle } from '@/components/charts/analytics-chart';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type {
    AnalyticsChartData,
    AnalyticsFilterValues,
} from '@/types/analytics';

type Props = {
    area: string;
    chart: AnalyticsChartData;
    filters: AnalyticsFilterValues;
    canExport: boolean;
};

const DRAWN = ['line', 'bar', 'stacked'];

function format(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    return typeof value === 'number' ? value.toLocaleString('en-NG') : value;
}

/**
 * One analytics chart (Phase 7C): the chart, its aggregate table under it
 * (sortable for tables, heat-coloured for the day × hour grid), the note,
 * and Download PNG / Download CSV (both logged as exports).
 */
export default function ChartCard({ area, chart, filters, canExport }: Props) {
    const handle = useRef<ChartHandle>(null);
    const [sort, setSort] = useState<{ key: string; dir: 1 | -1 } | null>(null);
    const [busy, setBusy] = useState(false);
    const drawn = DRAWN.includes(chart.kind) && chart.rows.length > 0;

    const rows = useMemo(() => {
        if (!sort) {
            return chart.rows;
        }

        return [...chart.rows].sort((a, b) => {
            const x = a[sort.key];
            const y = b[sort.key];

            if (x === y) {
                return 0;
            }
            if (x === null || x === undefined) {
                return 1;
            }
            if (y === null || y === undefined) {
                return -1;
            }

            return (x < y ? -1 : 1) * sort.dir;
        });
    }, [chart.rows, sort]);

    const max = useMemo(
        () =>
            chart.kind === 'heatmap'
                ? Math.max(
                      1,
                      ...chart.series.flatMap((s) => s.data.map((v) => v ?? 0)),
                  )
                : 1,
        [chart],
    );

    const png = async () => {
        const canvas = handle.current?.canvas();

        if (!canvas) {
            return;
        }

        setBusy(true);
        try {
            await downloadChartPng({
                canvas,
                area,
                chart: chart.key,
                title: chart.title,
                filters,
            });
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'The export failed.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <section className="rounded-xl border bg-card" aria-label={chart.title}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                <h2 className="text-sm font-medium">{chart.title}</h2>
                <div className="flex flex-wrap gap-1">
                    {chart.link && (
                        <Button variant="ghost" size="sm" asChild>
                            <Link
                                href={`${chart.link.path}?${new URLSearchParams(chart.link.query).toString()}`}
                            >
                                <MapIcon /> On the map
                            </Link>
                        </Button>
                    )}
                    {canExport && drawn && (
                        <Button
                            variant="ghost"
                            size="sm"
                            disabled={busy}
                            onClick={png}
                        >
                            <Image /> PNG
                        </Button>
                    )}
                    {canExport && (
                        <Button variant="ghost" size="sm" asChild>
                            <a
                                href={AnalyticsController.csv.url(
                                    { area, chart: chart.key },
                                    { query: filters },
                                )}
                            >
                                <Download /> CSV
                            </a>
                        </Button>
                    )}
                </div>
            </div>

            {drawn && (
                <div className="px-4 py-3">
                    <AnalyticsChart ref={handle} chart={chart} />
                </div>
            )}

            {chart.rows.length === 0 ? (
                <p className="px-4 py-6 text-sm text-muted-foreground">
                    Nothing in this period.
                </p>
            ) : (
                <div
                    className={cn(
                        'overflow-x-auto',
                        drawn && 'max-h-72 border-t',
                    )}
                >
                    <table className="w-full text-xs">
                        <thead className="sticky top-0 bg-muted/80 text-left">
                            <tr>
                                {chart.columns.map((c) => (
                                    <th
                                        key={c.key}
                                        className={cn(
                                            'px-3 py-2 font-medium whitespace-nowrap',
                                            c.numeric && 'text-right',
                                        )}
                                    >
                                        {chart.kind === 'table' ? (
                                            <button
                                                type="button"
                                                className="inline-flex items-center gap-1"
                                                onClick={() =>
                                                    setSort((s) => ({
                                                        key: c.key,
                                                        dir:
                                                            s?.key === c.key &&
                                                            s.dir === -1
                                                                ? 1
                                                                : -1,
                                                    }))
                                                }
                                            >
                                                {c.label}{' '}
                                                <ArrowUpDown className="size-3" />
                                            </button>
                                        ) : (
                                            c.label
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((row, i) => (
                                <tr key={i}>
                                    {chart.columns.map((c) => {
                                        const value = row[c.key];
                                        const heat =
                                            chart.kind === 'heatmap' &&
                                            c.key.startsWith('h') &&
                                            typeof value === 'number';

                                        return (
                                            <td
                                                key={c.key}
                                                className={cn(
                                                    'px-3 py-1.5 whitespace-nowrap tabular-nums',
                                                    c.numeric && 'text-right',
                                                )}
                                                style={
                                                    heat
                                                        ? {
                                                              background: `rgba(220, 38, 38, ${Math.min(0.85, (value as number) / max)})`,
                                                          }
                                                        : undefined
                                                }
                                            >
                                                {c.key === 'change_pct' &&
                                                typeof value === 'number'
                                                    ? `${value > 0 ? '+' : ''}${value}%`
                                                    : format(value)}
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {chart.note && (
                <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                    {chart.note}
                </p>
            )}
        </section>
    );
}
