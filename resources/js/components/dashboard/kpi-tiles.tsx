import { Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight, ChartLine } from 'lucide-react';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DashboardPeriod, KpiTile } from '@/types/dashboard';

/** Dashboard period selector (Phase 7C): 7, 30 or 90 days, for every widget. */
export function PeriodSelector({ period }: { period: DashboardPeriod }) {
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
                        router.get(
                            dashboard().url,
                            days === 30 ? {} : { days },
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
                href={`/analytics?period=${period.days}d`}
                className="ml-auto inline-flex items-center gap-1 text-xs underline"
            >
                <ChartLine className="size-3.5" /> Open analytics
            </Link>
        </div>
    );
}

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
                    className="rounded-xl border bg-card p-3 hover:bg-accent/50"
                >
                    <p className="text-xs text-muted-foreground">{t.label}</p>
                    <p className="text-2xl font-semibold tabular-nums">
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
