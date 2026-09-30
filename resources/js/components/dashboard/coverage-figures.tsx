import { Link } from '@inertiajs/react';
import CoverageReportController from '@/actions/App/Http/Controllers/Security/CoverageReportController';
import StatCard from '@/components/dashboard/stat-card';
import {
    CoverageStatusBadge,
    RatingBadge,
} from '@/components/security/coverage-badges';
import type { CoverageRating, CoverageStatus } from '@/types/coverage';
import type { NamedRef } from '@/types/local-government';

type Status = { value: CoverageStatus | 'missing'; label: string };
type Rating = { value: CoverageRating; label: string };

export type LgaCoverageData = {
    period: string;
    due: string;
    current: { ulid: string; status: Status } | null;
    lastRating: (Rating & { period: string }) | null;
    uncovered: { count: number; period: string } | null;
    canCreate: boolean;
};

export type StateCoverageData = {
    period: string;
    due: string;
    lgas: {
        lga: NamedRef;
        report: string | null;
        status: Status;
        rating: Rating | null;
    }[];
    awaitingReview: {
        ulid: string;
        lga: string;
        period: string;
        submitted_at: string | null;
    }[];
};

export type NationalCoverageData = {
    period: string;
    compliance: {
        state: NamedRef;
        lgas: number;
        submitted: number;
        percent: number;
    }[];
    poorlyCovered: {
        state: NamedRef;
        lgas: { report: string; lga: string; rating: Rating }[];
    }[];
};

const dateLabel = (iso: string) =>
    new Date(`${iso}T00:00:00`).toLocaleDateString('en-NG', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

/** LGA chief: this period's report, the last rating, uncovered communities. */
export function LgaCoverage({ data }: { data: LgaCoverageData }) {
    const status = data.current?.status ?? {
        value: 'missing' as const,
        label: 'Not started',
    };

    return (
        <section className="space-y-3" aria-label="Security coverage">
            <h2 className="text-sm font-semibold">Security coverage</h2>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    label={`Report for ${data.period}`}
                    value={<CoverageStatusBadge status={status} />}
                    hint={`Due ${dateLabel(data.due)}`}
                    href={
                        data.current
                            ? CoverageReportController.show(data.current.ulid)
                            : data.canCreate
                              ? CoverageReportController.create()
                              : undefined
                    }
                />
                <StatCard
                    label="Last submitted rating"
                    value={<RatingBadge rating={data.lastRating} />}
                    hint={data.lastRating?.period}
                />
                <StatCard
                    label="Uncovered communities"
                    value={data.uncovered?.count ?? '—'}
                    hint={
                        data.uncovered
                            ? `In ${data.uncovered.period}`
                            : 'No report yet'
                    }
                    tone={
                        (data.uncovered?.count ?? 0) > 0 ? 'warning' : 'default'
                    }
                />
            </div>
        </section>
    );
}

/** State roles: every LGA's report status for the current period. */
export function StateCoverage({ data }: { data: StateCoverageData }) {
    return (
        <section className="space-y-3" aria-label="Security coverage">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-sm font-semibold">
                    Coverage reports: {data.period}
                </h2>
                <span className="text-xs text-muted-foreground">
                    Due {dateLabel(data.due)}
                </span>
            </div>
            {data.awaitingReview.length > 0 && (
                <div className="rounded-lg border border-blue-500/40 p-3 text-sm">
                    <strong>
                        {data.awaitingReview.length} report
                        {data.awaitingReview.length === 1 ? '' : 's'} awaiting
                        review:
                    </strong>{' '}
                    {data.awaitingReview.map((r, i) => (
                        <span key={r.ulid}>
                            {i > 0 && ', '}
                            <Link
                                href={CoverageReportController.show(r.ulid)}
                                className="underline"
                            >
                                {r.lga} ({r.period})
                            </Link>
                        </span>
                    ))}
                </div>
            )}
            <div className="overflow-x-auto rounded-lg border">
                <table className="w-full text-sm" data-test="coverage-lgas">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">LGA</th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="px-3 py-2 font-medium">Rating</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {data.lgas.map((row) => (
                            <tr key={row.lga.ulid}>
                                <td className="px-3 py-2">
                                    {row.report ? (
                                        <Link
                                            href={CoverageReportController.show(
                                                row.report,
                                            )}
                                            className="hover:underline"
                                        >
                                            {row.lga.name}
                                        </Link>
                                    ) : (
                                        row.lga.name
                                    )}
                                </td>
                                <td className="px-3 py-2">
                                    <CoverageStatusBadge status={row.status} />
                                </td>
                                <td className="px-3 py-2">
                                    <RatingBadge rating={row.rating} />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

/** National: compliance by state, and poorly covered LGAs. */
export function NationalCoverage({ data }: { data: NationalCoverageData }) {
    return (
        <section className="space-y-3" aria-label="Security coverage">
            <h2 className="text-sm font-semibold">
                Coverage report compliance: {data.period}
            </h2>
            <div className="grid gap-6 lg:grid-cols-2">
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">State</th>
                                <th className="px-3 py-2 text-right font-medium">
                                    Submitted
                                </th>
                                <th className="px-3 py-2 font-medium">
                                    Compliance
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {data.compliance.map((row) => (
                                <tr key={row.state.ulid}>
                                    <td className="px-3 py-2">
                                        {row.state.name}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.submitted} / {row.lgas}
                                    </td>
                                    <td className="px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <div
                                                className="h-2 w-24 rounded bg-muted"
                                                aria-hidden
                                            >
                                                <div
                                                    className="h-2 rounded bg-primary"
                                                    style={{
                                                        width: `${row.percent}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="tabular-nums">
                                                {row.percent}%
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="space-y-2 rounded-lg border p-4 text-sm">
                    <h3 className="font-medium">
                        LGAs rated inadequate or critical
                    </h3>
                    {data.poorlyCovered.length === 0 ? (
                        <p className="text-muted-foreground">None.</p>
                    ) : (
                        data.poorlyCovered.map((group) => (
                            <div key={group.state.ulid}>
                                <div className="font-medium">
                                    {group.state.name}
                                </div>
                                <ul className="ml-4 list-disc">
                                    {group.lgas.map((l) => (
                                        <li key={l.report}>
                                            <Link
                                                href={CoverageReportController.show(
                                                    l.report,
                                                )}
                                                className="hover:underline"
                                            >
                                                {l.lga}
                                            </Link>{' '}
                                            <RatingBadge rating={l.rating} />
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))
                    )}
                </div>
            </div>
        </section>
    );
}
