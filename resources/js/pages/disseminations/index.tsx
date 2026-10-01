import { Head, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import DisseminationController from '@/actions/App/Http/Controllers/Disseminations/DisseminationController';
import NoteDialog from '@/components/disseminations/note-dialog';
import DisseminationStatusBadge from '@/components/disseminations/status-badge';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/time';
import { cn } from '@/lib/utils';
import type { InboxRequestRow, InboxRow } from '@/types/disseminations';

type Tab = 'received' | 'sent' | 'requests';

type Props = {
    tab: Tab;
    received: InboxRow[] | null;
    sent: InboxRow[] | null;
    requests: InboxRequestRow[] | null;
    can: { requests: boolean };
};

const TAB_LABELS: Record<Tab, string> = {
    received: 'Shared with us',
    sent: 'Shared by us',
    requests: 'Dissemination requests',
};

/**
 * Dissemination inbox (Phase 7A): threat reports and flashpoints shared
 * with us (unacknowledged first), those we shared, and LGA chiefs'
 * requests for the State Director and Deputy. Only records the viewer can
 * see are listed.
 */
export default function DisseminationsIndex({
    tab,
    received,
    sent,
    requests,
    can,
}: Props) {
    const [declining, setDeclining] = useState<string | null>(null);
    const tabs = (Object.keys(TAB_LABELS) as Tab[]).filter(
        (t) => t !== 'requests' || can.requests,
    );

    return (
        <>
            <Head title="Shared threats & flashpoints" />
            <div className="space-y-6 p-4">
                <Heading
                    title="Shared threats & flashpoints"
                    description="Threat reports and flashpoints shared across state and LGA borders. Shared records are read-only for recipients and end when revoked or expired."
                />

                <nav className="flex gap-1 border-b" aria-label="Sections">
                    {tabs.map((name) => (
                        <Link
                            key={name}
                            href={DisseminationController.index({
                                query: { tab: name },
                            })}
                            className={cn(
                                '-mb-px border-b-2 px-3 py-2 text-sm',
                                tab === name
                                    ? 'border-primary font-medium'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={tab === name ? 'page' : undefined}
                        >
                            {TAB_LABELS[name]}
                        </Link>
                    ))}
                </nav>

                {tab === 'received' && received && (
                    <Rows
                        rows={received}
                        empty="Nothing has been shared with you."
                        direction="received"
                    />
                )}
                {tab === 'sent' && sent && (
                    <Rows
                        rows={sent}
                        empty="Nothing shared yet."
                        direction="sent"
                    />
                )}
                {tab === 'requests' &&
                    requests &&
                    (requests.length === 0 ? (
                        <EmptyState
                            title="No requests"
                            description="LGA chiefs have not asked for anything to be shared."
                        />
                    ) : (
                        <ul
                            className="divide-y rounded-lg border"
                            aria-label="Dissemination requests"
                        >
                            {requests.map((r) => (
                                <li
                                    key={r.ulid}
                                    className="flex flex-wrap items-start justify-between gap-3 p-3 text-sm"
                                >
                                    <div className="space-y-1">
                                        <p className="font-medium">
                                            <Link
                                                href={r.subject.href}
                                                className="hover:underline"
                                            >
                                                {r.subject.kind}{' '}
                                                {r.subject.label}
                                            </Link>{' '}
                                            · {r.status.label}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {r.requested_by ?? 'An LGA chief'}{' '}
                                            on {formatDateTime(r.requested_at)}{' '}
                                            suggests{' '}
                                            {r.targets
                                                .map((t) => t.name)
                                                .join(', ') ||
                                                'no specific place'}
                                            .
                                        </p>
                                        <p>Reason: {r.reason}</p>
                                        {r.decision_note && (
                                            <p className="text-muted-foreground">
                                                Note: {r.decision_note}
                                            </p>
                                        )}
                                    </div>
                                    {r.can_decide && (
                                        <div className="flex gap-2">
                                            <Button size="sm" asChild>
                                                <Link href={r.subject.href}>
                                                    Open to disseminate
                                                </Link>
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setDeclining(r.ulid)
                                                }
                                            >
                                                Decline
                                            </Button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ))}
            </div>
            {declining && (
                <NoteDialog
                    open
                    onOpenChange={(open) => !open && setDeclining(null)}
                    title="Decline the request"
                    description="The LGA chief is told, with your note."
                    confirmLabel="Decline"
                    url={DisseminationController.decline.url(declining)}
                />
            )}
        </>
    );
}

function Rows({
    rows,
    empty,
    direction,
}: {
    rows: InboxRow[];
    empty: string;
    direction: 'received' | 'sent';
}) {
    if (rows.length === 0) {
        return <EmptyState title="Nothing here" description={empty} />;
    }

    return (
        <div className="overflow-x-auto rounded-lg border">
            <table className="w-full text-sm">
                <thead className="bg-muted/50 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Record</th>
                        <th className="px-3 py-2 font-medium">
                            {direction === 'received' ? 'From' : 'Shared with'}
                        </th>
                        <th className="px-3 py-2 font-medium">Shared</th>
                        <th className="px-3 py-2 font-medium">Until</th>
                        <th className="px-3 py-2 font-medium">Status</th>
                        <th className="px-3 py-2" />
                    </tr>
                </thead>
                <tbody className="divide-y">
                    {rows.map((d) => (
                        <tr
                            key={d.ulid}
                            className={cn(
                                d.status === 'unacknowledged' &&
                                    direction === 'received' &&
                                    'bg-cyan-50/60 dark:bg-cyan-950/20',
                            )}
                        >
                            <td className="px-3 py-2">
                                <Link
                                    href={d.subject.href}
                                    className="font-medium hover:underline"
                                >
                                    {d.subject.label}
                                </Link>
                                <div className="text-xs text-muted-foreground">
                                    {d.subject.kind}
                                    {d.subject.threat_type
                                        ? ` · ${d.subject.threat_type}`
                                        : ''}
                                </div>
                            </td>
                            <td className="px-3 py-2">
                                {direction === 'received' ? (
                                    <>
                                        {d.source}
                                        <div className="text-xs text-muted-foreground">
                                            {d.source_lga} LGA
                                        </div>
                                    </>
                                ) : (
                                    <>
                                        {d.target}
                                        {d.onward && (
                                            <div className="text-xs text-muted-foreground">
                                                forwarded
                                            </div>
                                        )}
                                    </>
                                )}
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {formatDateTime(d.disseminated_at)}
                                <div className="text-xs text-muted-foreground">
                                    {d.disseminated_by}
                                </div>
                            </td>
                            <td className="px-3 py-2 whitespace-nowrap">
                                {formatDateTime(d.expires_at)}
                            </td>
                            <td className="px-3 py-2">
                                <DisseminationStatusBadge status={d.status} />
                            </td>
                            <td className="px-3 py-2 text-right">
                                {d.can_acknowledge && (
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                DisseminationController.acknowledge.url(
                                                    d.ulid,
                                                ),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Check /> Acknowledge
                                    </Button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

DisseminationsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Shared threats & flashpoints',
            href: DisseminationController.index(),
        },
    ],
};
