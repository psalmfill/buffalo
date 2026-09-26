import { Link } from '@inertiajs/react';
import { History, Info, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import VulnerableFiguresController from '@/actions/App/Http/Controllers/CommunityInformation/VulnerableFiguresController';
import EmptyState from '@/components/empty-state';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import ConfirmActionDialog from '@/components/users/confirm-action-dialog';
import type {
    Figure,
    FigureTotals,
    InformationCan,
    VulnerableTab,
} from '@/types/community-information';

const number = (value: number) => value.toLocaleString('en-NG');

function FiguresTable({
    figures,
    totals,
    showCommunity = false,
    actions,
}: {
    figures: Figure[];
    totals?: FigureTotals;
    showCommunity?: boolean;
    actions?: (figure: Figure) => React.ReactNode;
}) {
    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        {showCommunity && (
                            <th className="px-3 py-2 font-medium">Community</th>
                        )}
                        <th className="px-3 py-2 font-medium">Category</th>
                        <th className="px-3 py-2 text-right font-medium">
                            Adult M
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Adult F
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Child M
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Child F
                        </th>
                        <th className="px-3 py-2 text-right font-medium">
                            Total
                        </th>
                        <th className="px-3 py-2 font-medium">As of</th>
                        <th className="px-3 py-2 font-medium">Source</th>
                        {actions && (
                            <th className="px-3 py-2">
                                <span className="sr-only">Actions</span>
                            </th>
                        )}
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {figures.map((figure) => (
                        <tr key={figure.ulid}>
                            {showCommunity && (
                                <td className="px-3 py-2">
                                    {figure.community?.name ?? 'LGA-wide'}
                                </td>
                            )}
                            <td className="px-3 py-2">
                                {figure.category.label}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(figure.adult_male)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(figure.adult_female)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(figure.child_male)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(figure.child_female)}
                            </td>
                            <td className="px-3 py-2 text-right font-medium tabular-nums">
                                {number(figure.total)}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {figure.as_of_date}
                            </td>
                            <td className="px-3 py-2 text-muted-foreground">
                                {figure.source}
                            </td>
                            {actions && (
                                <td className="px-3 py-2 text-right whitespace-nowrap">
                                    {actions(figure)}
                                </td>
                            )}
                        </tr>
                    ))}
                </tbody>
                {totals && figures.length > 0 && (
                    <tfoot className="border-t bg-muted/30 font-medium">
                        <tr>
                            {showCommunity && <td />}
                            <td className="px-3 py-2">Total</td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(totals.adult_male)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(totals.adult_female)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(totals.child_male)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(totals.child_female)}
                            </td>
                            <td className="px-3 py-2 text-right tabular-nums">
                                {number(totals.total)}
                            </td>
                            <td colSpan={actions ? 3 : 2} />
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

/**
 * Vulnerable population figures: aggregate counts only. The latest figure
 * per category (LGA-wide, or the community's), each community's latest
 * figures on an LGA page, and one category's history.
 */
export default function VulnerablePopulationPanel({
    lgaUlid,
    communityUlid,
    baseUrl,
    data,
    can,
}: {
    lgaUlid: string;
    communityUlid?: string;
    /** The current page's tab URL (history links add ?history=). */
    baseUrl: string;
    data: VulnerableTab;
    can: InformationCan;
}) {
    const [deleting, setDeleting] = useState<Figure | null>(null);
    const separator = baseUrl.includes('?') ? '&' : '?';

    const actions = (figure: Figure) => (
        <>
            <Button variant="ghost" size="icon" asChild>
                <Link
                    href={`${baseUrl}${separator}history=${figure.category.value}`}
                    preserveScroll
                    aria-label={`History of ${figure.category.label}`}
                >
                    <History />
                </Link>
            </Button>
            {can.addVulnerableFigures && (
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={VulnerableFiguresController.edit(figure.ulid)}
                        aria-label={`Correct ${figure.category.label}`}
                    >
                        <Pencil />
                    </Link>
                </Button>
            )}
            {can.deleteVulnerableFigures && (
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`Delete ${figure.category.label} figure`}
                    onClick={() => setDeleting(figure)}
                >
                    <Trash2 />
                </Button>
            )}
        </>
    );

    return (
        <div className="space-y-6">
            <Alert>
                <Info />
                <AlertDescription>
                    Aggregate counts only. Never record names or any detail that
                    could identify a person.
                </AlertDescription>
            </Alert>

            <section className="space-y-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-semibold">
                        {communityUlid
                            ? 'Latest figures for this community'
                            : 'Latest LGA-wide figures'}
                    </h3>
                    {can.addVulnerableFigures && (
                        <Button asChild>
                            <Link
                                href={VulnerableFiguresController.create(
                                    lgaUlid,
                                    {
                                        query: communityUlid
                                            ? { community: communityUlid }
                                            : {},
                                    },
                                )}
                            >
                                <Plus /> Add figures
                            </Link>
                        </Button>
                    )}
                </div>
                {data.latest.length === 0 ? (
                    <div className="rounded-lg border">
                        <EmptyState
                            title="No figures recorded"
                            description="Add the latest figures by category."
                        />
                    </div>
                ) : (
                    <FiguresTable
                        figures={data.latest}
                        totals={data.totals}
                        actions={actions}
                    />
                )}
            </section>

            {data.history && (
                <section className="space-y-3" data-test="figure-history">
                    <div className="flex items-center justify-between">
                        <h3 className="text-sm font-semibold">
                            History:{' '}
                            {data.history[0]?.category.label ??
                                data.historyCategory}
                        </h3>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={baseUrl} preserveScroll>
                                Close history
                            </Link>
                        </Button>
                    </div>
                    <FiguresTable figures={data.history} actions={actions} />
                </section>
            )}

            {!communityUlid && data.byCommunity.length > 0 && (
                <section className="space-y-3">
                    <h3 className="text-sm font-semibold">
                        Latest figures by community
                    </h3>
                    <p className="text-xs text-muted-foreground">
                        Community figures are breakdowns; they are not added to
                        the LGA-wide totals.
                    </p>
                    <FiguresTable
                        figures={data.byCommunity}
                        showCommunity
                        actions={actions}
                    />
                </section>
            )}

            {deleting && (
                <ConfirmActionDialog
                    open
                    onOpenChange={(open) => !open && setDeleting(null)}
                    title={`Delete the ${deleting.category.label} figure of ${deleting.as_of_date}?`}
                    description="The figure is removed from lists and totals. The record and its history are kept."
                    confirmLabel="Delete"
                    destructive
                    requireReason
                    method="delete"
                    url={VulnerableFiguresController.destroy(deleting.ulid).url}
                />
            )}
        </div>
    );
}
