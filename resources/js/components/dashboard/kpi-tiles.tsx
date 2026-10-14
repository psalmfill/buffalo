import { Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, ChartLine } from 'lucide-react';
import { cn } from '@/lib/utils';
import type {
    DashboardPeriod,
    DashboardPlace,
    KpiTile,
} from '@/types/dashboard';

/** Dashboard period selector (Phase 7C): 7, 30 or 90 days, for every widget. */
export function PeriodSelector({
    period,
    analyticsPlace,
}: {
    period: DashboardPeriod;
    /** A state or zone view (Phase 11A.2): analytics open on it. */
    analyticsPlace?: DashboardPlace;
}) {
    return (
        <div
            className="flex flex-wrap items-center gap-2 text-sm"
            role="group"
            aria-label="Dashboard period"
        >
            <span className="text-muted-foreground">Period:</span>
            {period.options.map((days) => (
                <button
                    key={days}
                    type="button"
                    aria-pressed={period.days === days}
                    onClick={() =>
                        // The current dashboard (also a state or zone view,
                        // Phase 11A.2), keeping its other parameters.
                        router.get(
                            window.location.pathname,
                            {
                                ...Object.fromEntries(
                                    new URLSearchParams(window.location.search),
                                ),
                                days: days === 30 ? undefined : days,
                            },
                            { preserveScroll: true },
                        )
                    }
                    className={cn(
                        'rounded-md border px-2.5 py-1',
                        period.days === days
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    )}
                >
                    Last {days} days
                </button>
            ))}
            <Link
                href={`/analytics?period=${period.days}d${analyticsPlace ? `&${analyticsPlace.type}=${analyticsPlace.ulid}` : ''}`}
                className="ml-auto inline-flex items-center gap-1 text-xs underline"
            >
                <ChartLine className="size-3.5" /> Open analytics
            </Link>
        </div>
    );
}

/**
 * Tile colours by type (Phase 11A.3, configurable on the Theme page):
 * a left border and the figure. Full class names so Tailwind finds them.
 */
const TILE_COLOURS: Record<string, string> = {
    incidents: 'border-l-kpi-incidents [&_.kpi-value]:text-kpi-incidents',
    killed: 'border-l-kpi-casualties [&_.kpi-value]:text-kpi-casualties',
    abducted: 'border-l-kpi-casualties [&_.kpi-value]:text-kpi-casualties',
    verification:
        'border-l-kpi-verification [&_.kpi-value]:text-kpi-verification',
    immediate_threats: 'border-l-kpi-threats [&_.kpi-value]:text-kpi-threats',
    coverage: 'border-l-kpi-coverage [&_.kpi-value]:text-kpi-coverage',
};

/**
 * KPI tiles (Phase 7C) with the change versus the previous period. A rise
 * is not coloured as good or bad: more incidents and more verifications
 * mean different things.
 */
export function KpiTiles({ tiles }: { tiles: KpiTile[] }) {
    if (tiles.length === 0) {
        return null;
    }

    return (
        <div
            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
            aria-label="Key figures"
        >
            {tiles.map((t) => (
                <Link
                    key={t.key}
                    href={t.href}
                    className={cn(
                        'rounded-xl border border-l-4 bg-card p-3 hover:bg-accent/50',
                        TILE_COLOURS[t.key],
                    )}
                    data-kpi={t.key}
                >
                    <p className="text-xs text-muted-foreground">{t.label}</p>
                    <p className="kpi-value text-2xl font-semibold tabular-nums">
                        {t.value === null
                            ? '—'
                            : t.value.toLocaleString('en-NG')}
                        {t.unit && t.value !== null && (
                            <span className="text-base">{t.unit}</span>
                        )}
                    </p>
                    <p className="flex items-center gap-1 text-xs text-muted-foreground">
                        {t.change_pct === null ? (
                            t.previous === null ? (
                                'Now'
                            ) : (
                                `Previously ${t.previous}${t.unit ?? ''}`
                            )
                        ) : (
                            <>
                                {t.change_pct >= 0 ? (
                                    <ArrowUpRight className="size-3.5" />
                                ) : (
                                    <ArrowDownRight className="size-3.5" />
                                )}
                                {t.change_pct > 0 ? '+' : ''}
                                {t.change_pct}% vs previous period
                            </>
                        )}
                    </p>
                </Link>
            ))}
        </div>
    );
}

/** A dashboard widget's link to the matching analytics view. */
export function AnalyticsLink({ tab, days }: { tab: string; days: number }) {
    return (
        <Link
            href={`/analytics?tab=${tab}&period=${days}d`}
            className="text-xs font-normal underline"
        >
            Analyse
        </Link>
    );
}
