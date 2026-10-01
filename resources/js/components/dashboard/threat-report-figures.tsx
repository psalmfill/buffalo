import { Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import ThreatReportController from '@/actions/App/Http/Controllers/Threats/ThreatReportController';
import EmptyState from '@/components/empty-state';
import StatCard from '@/components/dashboard/stat-card';
import { ThreatList } from '@/components/threats/threat-display';
import type {
    LgaThreatFigures,
    NationalThreatFigures,
    StateThreatFigures,
} from '@/types/threats';

/**
 * Refetches the dashboard's threat figures when threat events arrive
 * (6B channels), debounced. Broadcasts carry only ULIDs.
 */
function useLiveThreats(channel: string) {
    const timer = useRef<number | null>(null);

    useEcho(
        channel,
        ['.threat.created', '.threat.changed'],
        () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
            timer.current = window.setTimeout(
                () => router.reload({ only: ['threatReports', 'badges'] }),
                1500,
            );
        },
        [channel],
    );

    useEffect(
        () => () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
        },
        [],
    );
}

function Panel({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card">
            <div className="flex items-center justify-between border-b px-4 py-3">
                <h2 className="text-sm font-medium">{title}</h2>
                {action}
            </div>
            <div className="p-4">{children}</div>
        </section>
    );
}

const allLink = (
    <Link
        href={ThreatReportController.index()}
        className="text-xs text-muted-foreground hover:underline"
    >
        All threat reports
    </Link>
);

/** LGA chief: active threats in my LGA. */
export function LgaThreats({ data }: { data: LgaThreatFigures }) {
    useLiveThreats(data.channel);

    return (
        <Panel title="Active threats in my LGA" action={allLink}>
            <ThreatList
                rows={data.active}
                empty="No active threat reports."
                label="Active threats in my LGA"
            />
        </Panel>
    );
}

/** State roles: by urgency, expiring soon, recently materialised. */
export function StateThreats({ data }: { data: StateThreatFigures }) {
    useLiveThreats(data.channel);

    return (
        <Panel title="Threat reports" action={allLink}>
            <div className="space-y-6">
                <div className="grid gap-3 sm:grid-cols-3">
                    {data.byUrgency.map((u) => (
                        <StatCard
                            key={u.value}
                            label={`Active · ${u.label}`}
                            value={u.total}
                            tone={
                                u.value === 'immediate' && u.total > 0
                                    ? 'danger'
                                    : 'default'
                            }
                            href={ThreatReportController.index({
                                query: { urgency: u.value },
                            })}
                        />
                    ))}
                </div>
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">Expiring soon</h3>
                    <ThreatList
                        rows={data.expiringSoon}
                        empty="None expiring within 48 hours."
                        label="Expiring soon"
                    />
                </div>
                <div className="space-y-2">
                    <h3 className="text-sm font-medium">
                        Recently materialised
                    </h3>
                    <ThreatList
                        rows={data.recentlyMaterialised}
                        empty="None in the last 14 days."
                        label="Recently materialised"
                    />
                </div>
            </div>
        </Panel>
    );
}

/** National: active immediate threats (top of the page). */
export function NationalImmediateThreats({
    data,
}: {
    data: NationalThreatFigures;
}) {
    useLiveThreats(data.channel);

    return (
        <Panel
            title={`Active immediate threats (${data.immediate.length})`}
            action={allLink}
        >
            <ThreatList
                rows={data.immediate}
                empty="No active immediate threat reports."
                label="Active immediate threats"
            />
        </Panel>
    );
}

/** National: active threats by state and type. */
export function NationalThreatsByState({
    data,
}: {
    data: NationalThreatFigures;
}) {
    const types = [...new Set(data.byStateAndType.map((r) => r.type))];
    const states = [...new Set(data.byStateAndType.map((r) => r.state))];
    const cell = (state: string, type: string) =>
        data.byStateAndType.find((r) => r.state === state && r.type === type)
            ?.total;

    return (
        <Panel title="Active threats by state and type">
            {states.length === 0 ? (
                <EmptyState title="No active threat reports" />
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left">
                            <tr>
                                <th className="px-2 py-1 font-medium">State</th>
                                {types.map((t) => (
                                    <th
                                        key={t}
                                        className="px-2 py-1 text-right font-medium"
                                    >
                                        {t}
                                    </th>
                                ))}
                                <th className="px-2 py-1 text-right font-medium">
                                    Total
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {states.map((s) => (
                                <tr key={s}>
                                    <td className="px-2 py-1">{s}</td>
                                    {types.map((t) => (
                                        <td
                                            key={t}
                                            className="px-2 py-1 text-right tabular-nums"
                                        >
                                            {cell(s, t) ?? '—'}
                                        </td>
                                    ))}
                                    <td className="px-2 py-1 text-right font-medium tabular-nums">
                                        {data.byStateAndType
                                            .filter((r) => r.state === s)
                                            .reduce((n, r) => n + r.total, 0)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}
