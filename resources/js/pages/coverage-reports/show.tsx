import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import CoverageReportController from '@/actions/App/Http/Controllers/Security/CoverageReportController';
import FormationController from '@/actions/App/Http/Controllers/Security/AgencyFormationController';
import ClassificationBadge from '@/components/community-information/classification-badge';
import InputError from '@/components/input-error';
import BuffaloMap from '@/components/map/BuffaloMap';
import type { MapMarker } from '@/components/map/BuffaloMap';
import {
    CoverageStatusBadge,
    RatingBadge,
} from '@/components/security/coverage-badges';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type {
    CoverageComparison,
    CoverageReportDetail,
    CoverageTotals,
    Deployment,
    TimelineEntry,
    UncoveredCommunity,
} from '@/types/coverage';
import type { MapView } from '@/types/dashboard';
import ExportReportButton from '@/components/reports/export-report-button';

type Props = {
    report: CoverageReportDetail;
    deployments: Deployment[];
    totals: CoverageTotals;
    uncovered: UncoveredCommunity[];
    communityCount: number;
    comparison: CoverageComparison | null;
    timeline: TimelineEntry[];
    map: MapView;
    can: { update: boolean; submit: boolean; review: boolean; delete: boolean };
};

const CHANGE_LABELS: Record<CoverageComparison['rating']['change'], string> = {
    improved: 'improved',
    worsened: 'worsened',
    unchanged: 'unchanged',
    unknown: 'not comparable',
};

/** A coverage report, read-only, with its figures and workflow actions. */
export default function CoverageReportShow({
    report,
    deployments,
    totals,
    uncovered,
    communityCount,
    comparison,
    timeline,
    map,
    can,
}: Props) {
    const [discarding, setDiscarding] = useState(false);
    const returnForm = useForm({ reason: '' });
    const reviewForm = useForm({ comment: '' });

    const returnReport = (event: FormEvent) => {
        event.preventDefault();
        returnForm.post(
            CoverageReportController.returnReport(report.ulid).url,
            {
                preserveScroll: true,
            },
        );
    };

    const markReviewed = (event: FormEvent) => {
        event.preventDefault();
        reviewForm.post(CoverageReportController.review(report.ulid).url, {
            preserveScroll: true,
        });
    };

    const markers: MapMarker[] = uncovered
        .filter((c) => c.latitude !== null && c.longitude !== null)
        .map((c) => ({
            id: c.ulid,
            position: [c.latitude as number, c.longitude as number],
            title: c.name,
            popup: 'Not covered by any deployment in this report',
            color: '#b91c1c',
        }));

    return (
        <>
            <Head
                title={`Coverage report: ${report.lga.name}, ${report.period}`}
            />
            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-xl font-semibold">
                                {report.lga.name} LGA · {report.period}
                            </h1>
                            <CoverageStatusBadge status={report.status} />
                            <ClassificationBadge
                                level={report.classification.value}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {report.period_type.label} coverage report ·{' '}
                            {report.state.name} State · {report.period_start} to{' '}
                            {report.period_end}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {report.status.value !== 'draft' && (
                            <ExportReportButton
                                type="coverage"
                                parameters={{ report: report.ulid }}
                                label="Export PDF"
                            />
                        )}
                        {can.update && (
                            <Button asChild>
                                <Link
                                    href={CoverageReportController.edit(
                                        report.ulid,
                                    )}
                                >
                                    <Pencil />{' '}
                                    {report.status.value === 'returned'
                                        ? 'Revise and resubmit'
                                        : 'Edit draft'}
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <Button
                                variant="outline"
                                onClick={() => setDiscarding(true)}
                            >
                                <Trash2 /> Discard draft
                            </Button>
                        )}
                    </div>
                </div>

                {report.status.value === 'reviewed' && (
                    <p
                        role="status"
                        className="rounded-lg border p-3 text-sm text-muted-foreground"
                    >
                        Reviewed
                        {report.reviewed_by
                            ? ` by ${report.reviewed_by.name}`
                            : ''}
                        {report.reviewed_at
                            ? ` on ${new Date(report.reviewed_at).toLocaleString('en-NG')}`
                            : ''}
                        . This report is locked.
                        {report.review_comment
                            ? ` Comment: ${report.review_comment}`
                            : ''}
                    </p>
                )}
                {report.status.value === 'returned' && report.return_reason && (
                    <p
                        role="status"
                        className="rounded-lg border border-amber-500/50 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        Returned
                        {report.returned_by
                            ? ` by ${report.returned_by.name}`
                            : ''}
                        : {report.return_reason}
                    </p>
                )}

                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Overall rating">
                        <RatingBadge rating={report.overall_rating} />
                    </Stat>
                    <Stat label="Personnel deployed">
                        {totals.personnel.toLocaleString('en-NG')}
                    </Stat>
                    <Stat label="Vehicles">
                        {totals.vehicles.toLocaleString('en-NG')}
                    </Stat>
                    <Stat label="Uncovered communities">
                        {uncovered.length} of {communityCount}
                    </Stat>
                </section>

                <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                    <div className="space-y-6">
                        <section className="space-y-2">
                            <h2 className="text-sm font-semibold">
                                Deployments
                            </h2>
                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full text-sm">
                                    <thead className="bg-muted/50 text-left">
                                        <tr>
                                            <th className="px-3 py-2 font-medium">
                                                Agency / formation
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Personnel
                                            </th>
                                            <th className="px-3 py-2 text-right font-medium">
                                                Vehicles
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Patrols
                                            </th>
                                            <th className="px-3 py-2 font-medium">
                                                Covers
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {deployments.length === 0 && (
                                            <tr>
                                                <td
                                                    colSpan={5}
                                                    className="px-3 py-4 text-muted-foreground"
                                                >
                                                    No deployments recorded.
                                                </td>
                                            </tr>
                                        )}
                                        {deployments.map((d) => (
                                            <tr
                                                key={d.ulid}
                                                className="align-top"
                                            >
                                                <td className="px-3 py-2">
                                                    <div className="font-medium">
                                                        {d.agency.name}
                                                    </div>
                                                    {d.formation ? (
                                                        <Link
                                                            href={FormationController.show(
                                                                d.formation
                                                                    .ulid,
                                                            )}
                                                            className="text-xs hover:underline"
                                                        >
                                                            {d.formation.name}
                                                        </Link>
                                                    ) : d.formation_hidden ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            A formation you
                                                            cannot view
                                                        </span>
                                                    ) : null}
                                                    {d.remarks && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {d.remarks}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {d.personnel_count}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {d.vehicles_count ?? '—'}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {d.patrol_frequency.label}
                                                </td>
                                                <td className="px-3 py-2">
                                                    {d.communities.length > 0
                                                        ? d.communities
                                                              .map(
                                                                  (c) => c.name,
                                                              )
                                                              .join(', ')
                                                        : '—'}
                                                    {d.areas_covered && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {d.areas_covered}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        {totals.by_agency.length > 0 && (
                            <section className="space-y-2">
                                <h2 className="text-sm font-semibold">
                                    Totals by agency
                                </h2>
                                <table className="w-full rounded-lg border text-sm">
                                    <tbody className="divide-y">
                                        {totals.by_agency.map((row) => (
                                            <tr key={row.agency.ulid}>
                                                <td className="px-3 py-2">
                                                    {row.agency.name}
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {row.personnel} personnel
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {row.vehicles} vehicles
                                                </td>
                                                <td className="px-3 py-2 text-right tabular-nums">
                                                    {row.deployments} deployment
                                                    {row.deployments === 1
                                                        ? ''
                                                        : 's'}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </section>
                        )}

                        <section className="space-y-3 rounded-lg border p-4">
                            <Text label="Summary" value={report.summary} />
                            <Text
                                label="Gaps identified"
                                value={report.gaps_identified}
                            />
                            <Text
                                label="Recommendations"
                                value={report.recommendations}
                            />
                        </section>
                    </div>

                    <div className="space-y-6">
                        <section className="space-y-2">
                            <h2 className="text-sm font-semibold">
                                Uncovered communities ({uncovered.length})
                            </h2>
                            {markers.length > 0 && (
                                <BuffaloMap
                                    className="h-64 rounded-lg border"
                                    markers={markers}
                                    fitMarkers
                                    {...(map.center
                                        ? { center: map.center, zoom: map.zoom }
                                        : {})}
                                />
                            )}
                            {uncovered.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Every community is covered.
                                </p>
                            ) : (
                                <ul className="max-h-60 space-y-1 overflow-y-auto text-sm">
                                    {uncovered.map((c) => (
                                        <li key={c.ulid}>{c.name}</li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="space-y-2 rounded-lg border p-4 text-sm">
                            <h2 className="font-semibold">
                                Compared with the previous report
                            </h2>
                            {comparison === null ? (
                                <p className="text-muted-foreground">
                                    No earlier submitted report of this period
                                    type.
                                </p>
                            ) : (
                                <ul className="space-y-1">
                                    <li>
                                        Previous:{' '}
                                        <Link
                                            href={CoverageReportController.show(
                                                comparison.report.ulid,
                                            )}
                                            className="hover:underline"
                                        >
                                            {comparison.report.period}
                                        </Link>
                                    </li>
                                    <li>
                                        Personnel:{' '}
                                        {comparison.personnel_change > 0
                                            ? '+'
                                            : ''}
                                        {comparison.personnel_change}
                                    </li>
                                    <li>
                                        Rating:{' '}
                                        {comparison.rating.previous ?? '—'} →{' '}
                                        {comparison.rating.current ?? '—'} (
                                        {
                                            CHANGE_LABELS[
                                                comparison.rating.change
                                            ]
                                        }
                                        )
                                    </li>
                                    <li>
                                        Newly uncovered:{' '}
                                        {comparison.newly_uncovered.length === 0
                                            ? 'none'
                                            : comparison.newly_uncovered
                                                  .map((c) => c.name)
                                                  .join(', ')}
                                    </li>
                                </ul>
                            )}
                        </section>

                        <section className="space-y-2">
                            <h2 className="text-sm font-semibold">Timeline</h2>
                            <ol className="space-y-2 border-l pl-4 text-sm">
                                {timeline.map((entry, index) => (
                                    <li key={index}>
                                        <div className="font-medium">
                                            {entry.label}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {entry.by ?? 'System'}
                                            {entry.at
                                                ? ` · ${new Date(entry.at).toLocaleString('en-NG')}`
                                                : ''}
                                        </div>
                                        {entry.note && (
                                            <div className="text-xs">
                                                {entry.note}
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        </section>

                        {can.review && (
                            <section className="space-y-4 rounded-lg border p-4">
                                <h2 className="text-sm font-semibold">
                                    Review
                                </h2>
                                <form
                                    onSubmit={markReviewed}
                                    className="space-y-2"
                                >
                                    <Label htmlFor="comment">
                                        Comment (optional)
                                    </Label>
                                    <Textarea
                                        id="comment"
                                        value={reviewForm.data.comment}
                                        onChange={(e) =>
                                            reviewForm.setData(
                                                'comment',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={reviewForm.errors.comment}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={reviewForm.processing}
                                    >
                                        Mark reviewed (locks the report)
                                    </Button>
                                </form>
                                <form
                                    onSubmit={returnReport}
                                    className="space-y-2"
                                >
                                    <Label htmlFor="reason">
                                        Reason for returning
                                    </Label>
                                    <Textarea
                                        id="reason"
                                        value={returnForm.data.reason}
                                        onChange={(e) =>
                                            returnForm.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    <InputError
                                        message={returnForm.errors.reason}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={returnForm.processing}
                                    >
                                        Return for changes
                                    </Button>
                                </form>
                            </section>
                        )}
                    </div>
                </div>
            </div>

            {discarding && (
                <ConfirmActionDialog
                    open
                    onOpenChange={setDiscarding}
                    title="Discard this draft?"
                    description="The draft is removed from lists. Its history is kept."
                    confirmLabel="Discard"
                    destructive
                    method="delete"
                    url={CoverageReportController.destroy(report.ulid).url}
                />
            )}
        </>
    );
}

function Stat({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-lg border p-4">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-1 text-lg font-semibold">{children}</div>
        </div>
    );
}

function Text({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <h3 className="text-xs text-muted-foreground">{label}</h3>
            <p className="text-sm whitespace-pre-line">{value ?? '—'}</p>
        </div>
    );
}

CoverageReportShow.layout = {
    breadcrumbs: [
        { title: 'Coverage reports', href: CoverageReportController.index() },
    ],
};
