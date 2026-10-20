import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import ArrestController from '@/actions/App/Http/Controllers/Arrests/ArrestController';
import BarChart from '@/components/charts/bar-chart';
import type { ArrestRow } from '@/types/arrests';

/** Arrests on the dashboards (Phase 11C), through the viewer's scopes. */

export type LgaArrestFigures = {
    count: number;
    days: number;
    latest: ArrestRow[];
};

export type StateArrestFigures = {
    count: number;
    days: number;
    byAgency: { label: string; count: number }[];
    byOffence: { label: string; count: number }[];
};

function Panel({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card" aria-label={title}>
            <h2 className="flex items-center justify-between border-b px-4 py-3 text-sm font-medium">
                {title}
                <Link
                    href={ArrestController.index()}
                    className="text-xs font-normal underline"
                >
                    All arrests
                </Link>
            </h2>
            {children}
        </section>
    );
}

export function LgaArrests({ data }: { data: LgaArrestFigures }) {
    return (
        <Panel title={`Arrests, last ${data.days} days`}>
            <div className="space-y-2 p-4 text-sm">
                <p className="text-2xl font-semibold tabular-nums">
                    {data.count}
                </p>
                {data.latest.length > 0 && (
                    <ul className="divide-y">
                        {data.latest.map((a) => (
                            <li key={a.ulid} className="py-1.5">
                                <Link
                                    href={ArrestController.show(a.ulid)}
                                    className="underline"
                                >
                                    {a.reference_no}
                                </Link>{' '}
                                · {a.agency.short_name} · {a.persons_count}{' '}
                                {a.persons_count === 1 ? 'person' : 'persons'}
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </Panel>
    );
}

export function StateArrests({ data }: { data: StateArrestFigures }) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <Panel
                title={`Arrests by offence, last ${data.days} days (${data.count} arrests)`}
            >
                <BarChart
                    rows={data.byOffence}
                    label="Arrested persons by offence"
                    horizontal
                />
            </Panel>
            <Panel title={`Arrests by agency, last ${data.days} days`}>
                <BarChart
                    rows={data.byAgency}
                    label="Arrests by agency"
                    horizontal
                    paletteIndex={2}
                />
            </Panel>
        </div>
    );
}
