import { Link, router } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import type { ReactNode } from 'react';
import { useEffect, useRef } from 'react';
import IncidentController from '@/actions/App/Http/Controllers/Incidents/IncidentController';
import IncidentListController from '@/actions/App/Http/Controllers/Incidents/IncidentListController';
import BarChart from '@/components/charts/bar-chart';
import { AnalyticsLink } from '@/components/dashboard/kpi-tiles';
import StatCard from '@/components/dashboard/stat-card';
import IncidentTable from '@/components/incidents/incident-table';
import { formatDateTime, timeAgo } from '@/lib/time';
import type {
    LgaIncidentFigures,
    NationalIncidentFigures,
    StateIncidentFigures,
} from '@/types/dashboard';

const EVENTS = [
    '.incident.submitted',
    '.incident.status-changed',
    '.incident.escalated',
    '.incident.updated',
];

/**
 * Refetches the dashboard's incident figures when incident events arrive
 * (5C channels), debounced. Broadcasts carry only ULIDs; the figures come
 * back through the normal scoped route.
 */
function useLiveIncidents(channel: string) {
    const timer = useRef<number | null>(null);

    useEcho(
        channel,
        EVENTS,
        () => {
            if (timer.current !== null) {
                window.clearTimeout(timer.current);
            }
            timer.current = window.setTimeout(
                () => router.reload({ only: ['incidents'] }),
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
    children,
    action,
}: {
    title: string;
    children: ReactNode;
    action?: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card" aria-label={title}>
            <h2 className="flex items-center justify-between border-b px-4 py-3 text-sm font-medium">
                {title}
                {action}
            </h2>
            {children}
        </section>
    );
}

function ListLink({
    query,
    children,
}: {
    query: Record<string, string | string[]>;
    children: ReactNode;
}) {
    return (
        <Link
            href={IncidentListController.index({ query })}
            className="text-xs font-normal underline"
        >
            {children}
        </Link>
    );
}

/** LGA Security Chief. */
export function LgaIncidents({ data }: { data: LgaIncidentFigures }) {
    useLiveIncidents(data.channel);
    const returned = data.returned.length;

    return (
        <div className="grid gap-4">
            {returned > 0 && (
                <Panel
                    title={`Returned to you: ${returned} report${returned === 1 ? '' : 's'} need${returned === 1 ? 's' : ''} your action`}
                >
                    <div className="border-l-4 border-amber-500">
                        <IncidentTable rows={data.returned} compact />
                    </div>
                </Panel>
            )}
            <div className="grid gap-4 xl:grid-cols-[2fr_1fr]">
                <Panel
                    title={`Incidents in the last ${data.days} days by type`}
                    action={<AnalyticsLink tab="incidents" days={data.days} />}
                >
                    <BarChart
                        rows={data.byType}
                        label={`Incidents by type, last ${data.days} days`}
                        horizontal
                    />
                </Panel>
                <div className="grid content-start gap-4">
                    {data.myOpenByStatus.map((row) => (
                        <StatCard
                            key={row.status.value}
                            label={`My open reports: ${row.status.label.toLowerCase()}`}
                            value={row.count}
                            tone={
                                row.status.value === 'returned' && row.count > 0
                                    ? 'warning'
                                    : 'default'
                            }
                            href={IncidentListController.index({
                                query: {
                                    mine: '1',
                                    statuses: [row.status.value],
                                },
                            })}
                        />
                    ))}
                    <StatCard
                        label="My drafts"
                        value={data.drafts.count}
                        href={IncidentListController.index({
                            query: { mine: '1', statuses: ['draft'] },
                        })}
                    />
                </div>
            </div>
            {data.drafts.latest.length > 0 && (
                <Panel title="My drafts">
                    <ul className="divide-y text-sm">
                        {data.drafts.latest.map((d) => (
                            <li
                                key={d.ulid}
                                className="flex justify-between px-4 py-2"
                            >
                                <Link
                                    href={IncidentController.show(d.ulid)}
                                    className="hover:underline"
                                >
                                    {d.title}
                                </Link>
                                <span className="text-xs text-muted-foreground">
                                    {timeAgo(d.updated_at)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
            <Panel
                title="Latest incidents in my LGA"
                action={<ListLink query={{}}>All incidents</ListLink>}
            >
                <div className="p-2">
                    <IncidentTable rows={data.latest} compact />
                </div>
            </Panel>
        </div>
    );
}

/** State Director and Deputy. */
export function StateIncidents({ data }: { data: StateIncidentFigures }) {
    useLiveIncidents(data.channel);

    return (
        <div className="grid gap-4">
            <Panel
                title={`Awaiting verification: ${data.awaiting.count}${data.awaiting.overdue > 0 ? ` (${data.awaiting.overdue} overdue)` : ''}`}
                action={
                    <ListLink query={{ statuses: ['submitted'] }}>
                        Open the list
                    </ListLink>
                }
            >
                <div className="p-2">
                    {data.awaiting.rows.length === 0 ? (
                        <p className="px-2 py-4 text-sm text-muted-foreground">
                            Nothing awaiting verification.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {data.awaiting.rows.map((row) => (
                                <li
                                    key={row.ulid}
                                    className={`flex flex-wrap items-center justify-between gap-2 px-2 py-2 ${row.overdue ? 'bg-amber-50 dark:bg-amber-950/40' : ''}`}
                                >
                                    <Link
                                        href={IncidentController.show(row.ulid)}
                                        className="hover:underline"
                                    >
                                        <span className="font-mono text-xs">
                                            {row.reference_no}
                                        </span>{' '}
                                        · {row.title}
                                        <span className="block text-xs text-muted-foreground">
                                            {row.type.name} · {row.lga.name} ·{' '}
                                            {row.severity.label}
                                        </span>
                                    </Link>
                                    <span
                                        className={`text-xs ${row.overdue ? 'font-semibold text-amber-700 dark:text-amber-300' : 'text-muted-foreground'}`}
                                    >
                                        {row.overdue ? 'Overdue · ' : ''}
                                        submitted {timeAgo(row.submitted_at)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </Panel>
            <div className="grid gap-4 xl:grid-cols-2">
                <Panel
                    title={`Critical and high incidents open: ${data.criticalHigh.count}`}
                >
                    <div className="p-2">
                        <IncidentTable
                            rows={data.criticalHigh.rows}
                            compact
                            empty="None open."
                        />
                    </div>
                </Panel>
                <Panel title="Escalated incidents">
                    <div className="p-2">
                        <IncidentTable
                            rows={data.escalated}
                            compact
                            empty="No open escalations."
                        />
                    </div>
                </Panel>
            </div>
            <div className="grid gap-4 xl:grid-cols-3">
                <Panel
                    title={`Last ${data.days} days by LGA`}
                    action={<AnalyticsLink tab="incidents" days={data.days} />}
                >
                    <BarChart
                        rows={data.byLga}
                        label={`Incidents by LGA, last ${data.days} days`}
                        horizontal
                    />
                </Panel>
                <Panel
                    title={`Last ${data.days} days by type`}
                    action={<AnalyticsLink tab="incidents" days={data.days} />}
                >
                    <BarChart
                        rows={data.byType}
                        label={`Incidents by type, last ${data.days} days`}
                        horizontal
                    />
                </Panel>
                <Panel
                    title={`Casualties, last ${data.days} days`}
                    action={<AnalyticsLink tab="casualties" days={data.days} />}
                >
                    <BarChart
                        rows={data.casualtiesByOutcome}
                        label={`People affected by outcome, last ${data.days} days`}
                        colour="#b91c1c"
                    />
                </Panel>
            </div>
        </div>
    );
}

/** DG and DDGs (national or their zones). */
export function NationalIncidents({ data }: { data: NationalIncidentFigures }) {
    useLiveIncidents(data.channel);

    return (
        <div className="grid gap-4">
            <Panel
                title={`Escalated, awaiting acknowledgement: ${data.escalatedUnacknowledged.count}`}
                action={
                    <ListLink query={{ escalated: 'unacknowledged' }}>
                        Open the list
                    </ListLink>
                }
            >
                <div
                    className={`p-2 ${data.escalatedUnacknowledged.count > 0 ? 'border-l-4 border-red-600' : ''}`}
                >
                    <IncidentTable
                        rows={data.escalatedUnacknowledged.rows}
                        showState
                        compact
                        empty="No escalations awaiting acknowledgement."
                    />
                </div>
            </Panel>
            <Panel
                title={`Critical incidents in the last 7 days: ${data.critical7.count}`}
                action={
                    <ListLink query={{ severities: ['critical'] }}>
                        Open the list
                    </ListLink>
                }
            >
                <div className="p-2">
                    <IncidentTable
                        rows={data.critical7.rows}
                        showState
                        compact
                        empty="No critical incidents in the last 7 days."
                    />
                </div>
            </Panel>
            <div className="grid gap-4 xl:grid-cols-2">
                <Panel
                    title={`Last ${data.days} days by state`}
                    action={<AnalyticsLink tab="incidents" days={data.days} />}
                >
                    <BarChart
                        rows={data.byState}
                        label={`Incidents by state, last ${data.days} days`}
                        horizontal
                        height={Math.max(220, data.byState.length * 22)}
                    />
                </Panel>
                <Panel
                    title={`Last ${data.days} days by category`}
                    action={<AnalyticsLink tab="incidents" days={data.days} />}
                >
                    <BarChart
                        rows={data.byCategory}
                        label={`Incidents by category, last ${data.days} days`}
                        horizontal
                    />
                </Panel>
            </div>
            <div className="grid gap-4 xl:grid-cols-3">
                <Panel
                    title={`Casualties by outcome, last ${data.days} days`}
                    action={<AnalyticsLink tab="casualties" days={data.days} />}
                >
                    <BarChart
                        rows={data.casualtiesByOutcome}
                        label={`People affected by outcome, last ${data.days} days`}
                        colour="#b91c1c"
                    />
                </Panel>
                <Panel
                    title={`Casualties by group, last ${data.days} days`}
                    action={<AnalyticsLink tab="casualties" days={data.days} />}
                >
                    <BarChart
                        rows={data.casualtiesByGroup}
                        label={`People affected by group, last ${data.days} days`}
                        colour="#7c3aed"
                    />
                </Panel>
                <Panel
                    title="Security personnel casualties by agency"
                    action={<AnalyticsLink tab="casualties" days={data.days} />}
                >
                    <BarChart
                        rows={data.securityCasualtiesByAgency}
                        label={`Security personnel casualties by agency, last ${data.days} days`}
                        colour="#0f766e"
                    />
                </Panel>
            </div>
            <div className="grid gap-4 xl:grid-cols-[1fr_2fr]">
                <Panel
                    title="States with overdue verifications"
                    action={<AnalyticsLink tab="response" days={data.days} />}
                >
                    {data.statesOverdue.length === 0 ? (
                        <p className="px-4 py-6 text-sm text-muted-foreground">
                            None.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {data.statesOverdue.map((row) => (
                                <li
                                    key={row.label}
                                    className="flex justify-between px-4 py-2"
                                >
                                    {row.label}
                                    <span className="font-semibold text-amber-700 tabular-nums dark:text-amber-300">
                                        {row.count}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
            <p className="text-xs text-muted-foreground">
                Updated {formatDateTime(new Date().toISOString())}. Drafts and
                invalid reports are not counted.
            </p>
        </div>
    );
}
